<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TenantMember;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthTokens;
use App\Services\TenantContext;
use App\Services\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;

class AuthController extends Controller
{
    public function __construct(
        private AuthTokens $tokens,
        private TenantContext $context,
        private AuditLogger $audit,
    ) {}

    /** Sign up: creates the user and their first business, with them as owner. */
    public function register(Request $request, TenantProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(10)],
            'business_name' => ['required', 'string', 'max:255'],
        ]);

        [$user, $tenant] = DB::transaction(function () use ($data, $provisioner) {
            $user = User::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
            ]);
            $this->context->setUser($user->id);
            $tenant = $provisioner->create($user, ['name' => $data['business_name']]);

            return [$user, $tenant];
        });

        return $this->tokenResponse($user, $request, 201, ['tenant_id' => $tenant->id]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These details do not match our records.']);
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $data['password']])->save();
        }

        $this->audit->record('auth.login', $user, actorId: $user->id);

        return $this->tokenResponse($user, $request);
    }

    public function refresh(Request $request): JsonResponse
    {
        $plain = $this->refreshTokenFrom($request);
        $result = $plain ? $this->tokens->refresh($plain, $request) : null;

        if ($result === null) {
            return response()
                ->json(['message' => 'Session expired. Please sign in again.'], 401)
                ->withCookie($this->forgetRefreshCookie());
        }

        return $this->respondWithTokens($result['user'], $result['tokens'], $request);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        $this->tokens->revoke($this->refreshTokenFrom($request));

        return response()->json(['message' => 'Signed out.'])->withCookie($this->forgetRefreshCookie());
    }

    /** The signed-in user and every business they belong to. */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $memberships = TenantMember::withoutGlobalScope('tenant')
            ->with('tenant:id,name,status')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->get();

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'is_platform_admin']),
            'tenants' => $memberships
                ->filter(fn (TenantMember $m) => $m->tenant?->status === 'active')
                ->map(fn (TenantMember $m) => [
                    'id' => $m->tenant_id,
                    'name' => $m->tenant->name,
                    'role' => $m->role->value,
                ])->values(),
        ]);
    }

    private function tokenResponse(User $user, Request $request, int $status = 200, array $extra = []): JsonResponse
    {
        return $this->respondWithTokens($user, $this->tokens->issue($user, $request), $request, $status, $extra);
    }

    private function respondWithTokens(User $user, array $tokens, Request $request, int $status = 200, array $extra = []): JsonResponse
    {
        $body = [
            'access_token' => $tokens['access_token'],
            'token_type' => 'Bearer',
            'expires_at' => $tokens['expires_at'],
            'user' => $user->only(['id', 'name', 'email']),
            ...$extra,
        ];

        // Browsers get the refresh token only as an httpOnly cookie; other
        // clients that ask for it get it in the body.
        if ($request->boolean('refresh_token_in_body')) {
            $body['refresh_token'] = $tokens['refresh_token'];
        }

        return response()->json($body, $status)
            ->withCookie($this->refreshCookie($tokens['refresh_token'], $request));
    }

    private function refreshTokenFrom(Request $request): ?string
    {
        $value = $request->cookie(config('auth.refresh_cookie')) ?? $request->input('refresh_token');

        return is_string($value) ? $value : null;
    }

    private function refreshCookie(string $token, Request $request): Cookie
    {
        return cookie(
            name: config('auth.refresh_cookie'),
            value: $token,
            minutes: (int) config('auth.refresh_token_ttl_days') * 24 * 60,
            path: '/api/auth',
            secure: $request->isSecure() || app()->isProduction(),
            httpOnly: true,
            sameSite: 'strict',
        );
    }

    private function forgetRefreshCookie(): Cookie
    {
        return cookie()->forget(config('auth.refresh_cookie'), '/api/auth');
    }
}

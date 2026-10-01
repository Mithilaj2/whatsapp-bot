<?php

namespace App\Services;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues a short-lived access token (Sanctum) plus a refresh token that is
 * rotated on every use. Reusing a refresh token that was already exchanged
 * revokes its whole family and the user's access tokens, since it means the
 * token was copied.
 */
class AuthTokens
{
    /**
     * @return array{access_token: string, expires_at: string, refresh_token: string}
     */
    public function issue(User $user, Request $request, ?string $familyId = null): array
    {
        $accessMinutes = (int) config('sanctum.expiration');
        $expiresAt = now()->addMinutes($accessMinutes);
        $access = $user->createToken('access', ['*'], $expiresAt);

        $refresh = Str::random(64);
        RefreshToken::create([
            'user_id' => $user->id,
            'family_id' => $familyId ?? (string) Str::uuid7(),
            'token_hash' => hash('sha256', $refresh),
            'expires_at' => now()->addDays((int) config('auth.refresh_token_ttl_days')),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]);

        return [
            'access_token' => $access->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'refresh_token' => $refresh,
        ];
    }

    /**
     * @return array{user: User, tokens: array}|null null when the token is
     *                                               unknown, expired or reused
     */
    public function refresh(string $plainToken, Request $request): ?array
    {
        return DB::transaction(function () use ($plainToken, $request) {
            $token = RefreshToken::where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                return null;
            }

            if ($token->used_at !== null || $token->revoked_at !== null) {
                $this->revokeFamily($token->family_id);
                $token->user?->tokens()->delete();

                return null;
            }

            if ($token->expires_at->isPast()) {
                return null;
            }

            $token->forceFill(['used_at' => now()])->save();
            $user = $token->user;

            return [
                'user' => $user,
                'tokens' => $this->issue($user, $request, $token->family_id),
            ];
        });
    }

    public function revoke(?string $plainToken): void
    {
        if ($plainToken === null || $plainToken === '') {
            return;
        }

        $token = RefreshToken::where('token_hash', hash('sha256', $plainToken))->first();
        if ($token !== null) {
            $this->revokeFamily($token->family_id);
        }
    }

    /** Sign the user out everywhere, e.g. after a password change. */
    public function revokeAllFor(User $user): void
    {
        RefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
        $user->tokens()->delete();
    }

    private function revokeFamily(string $familyId): void
    {
        RefreshToken::where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}

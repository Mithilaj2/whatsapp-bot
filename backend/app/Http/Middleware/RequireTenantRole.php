<?php

namespace App\Http\Middleware;

use App\Enums\TenantRole;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('tenant.role:owner,admin')
 */
class RequireTenantRole
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $this->context->member()?->role;
        $allowed = array_map(fn (string $r) => TenantRole::from($r), $roles);

        if ($role === null || ! in_array($role, $allowed, true)) {
            return response()->json(['message' => 'You do not have permission to do this.'], 403);
        }

        return $next($request);
    }
}

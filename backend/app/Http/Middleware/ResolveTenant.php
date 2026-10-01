<?php

namespace App\Http\Middleware;

use App\Models\TenantMember;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the business for this request from the X-Tenant-Id header, and only
 * if the signed-in user is an active member of it. Must run after
 * SetDatabaseUser.
 */
class ResolveTenant
{
    public const HEADER = 'X-Tenant-Id';

    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->header(self::HEADER);

        if (! is_string($tenantId) || ! Str::isUuid($tenantId)) {
            return response()->json(['message' => 'Choose a business first.'], 400);
        }

        $member = TenantMember::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('status', 'active')
            ->first();

        // Same answer whether the business does not exist or the user is not
        // in it, so ids cannot be probed.
        if ($member === null) {
            return response()->json(['message' => 'Business not found.'], 404);
        }

        $this->context->setTenantId($tenantId);
        $tenant = $this->context->tenant();
        if ($tenant === null || $tenant->status !== 'active') {
            return response()->json(['message' => 'Business not found.'], 404);
        }
        $this->context->setTenant($tenant, $member);

        return $next($request);
    }
}

<?php

namespace App\Services;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantProvisioner
{
    public function __construct(
        private TenantContext $context,
        private AuditLogger $audit,
    ) {}

    /**
     * Create a business with the given user as its owner.
     *
     * The row-level security policy only accepts a new tenant whose id is the
     * current tenant, so the id is chosen here and set before inserting.
     */
    public function create(User $owner, array $attributes): Tenant
    {
        $tenantId = (string) Str::uuid7();

        return DB::transaction(fn () => $this->context->run($tenantId, function () use ($tenantId, $owner, $attributes) {
            $tenant = new Tenant($attributes);
            $tenant->id = $tenantId;
            $tenant->save();

            TenantMember::create([
                'tenant_id' => $tenantId,
                'user_id' => $owner->id,
                'role' => TenantRole::Owner,
            ]);

            $this->audit->record('tenant.created', $tenant, after: $tenant->only(['name']), actorId: $owner->id);

            return $tenant;
        }));
    }
}

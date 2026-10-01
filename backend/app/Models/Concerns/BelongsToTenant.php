<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For models whose table has a tenant_id and a row-level security policy.
 *
 * Postgres is the real boundary; this trait adds the same filter in the query
 * so that indexes starting with tenant_id are used, and fills tenant_id on
 * create from the current tenant.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $tenantId = app(TenantContext::class)->tenantId();
            if ($tenantId !== null) {
                $query->where($query->getModel()->qualifyColumn('tenant_id'), $tenantId);
            }
        });

        static::creating(function ($model) {
            $model->tenant_id ??= app(TenantContext::class)->tenantId();
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

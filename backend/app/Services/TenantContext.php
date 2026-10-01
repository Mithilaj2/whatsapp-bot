<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantMember;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The business (tenant) and user the current request or job acts for.
 *
 * Setting a tenant also sets the Postgres session settings app.tenant_id and
 * app.user_id, which the row-level security policies read. Without them the
 * database shows no tenant rows at all.
 */
class TenantContext
{
    private ?string $currentTenantId = null;

    private ?Tenant $tenant = null;

    private ?TenantMember $member = null;

    private ?string $userId = null;

    public function setUser(?string $userId): void
    {
        $this->userId = $userId;
        $this->setDatabaseSetting('app.user_id', $userId);
    }

    public function setTenant(Tenant $tenant, ?TenantMember $member = null): void
    {
        $this->setTenantId($tenant->id);
        $this->tenant = $tenant;
        $this->member = $member;
    }

    /**
     * Set only the tenant id, for code that has to act before the tenant row
     * is readable (creating a new tenant) or that only knows the id (a job).
     */
    public function setTenantId(?string $tenantId): void
    {
        $this->tenant = null;
        $this->member = null;
        $this->setDatabaseSetting('app.tenant_id', $tenantId);
        $this->currentTenantId = $tenantId;
    }

    public function tenantId(): ?string
    {
        return $this->currentTenantId;
    }

    public function tenant(): ?Tenant
    {
        if ($this->tenant === null && $this->currentTenantId !== null) {
            $this->tenant = Tenant::find($this->currentTenantId);
        }

        return $this->tenant;
    }

    public function member(): ?TenantMember
    {
        return $this->member;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    /**
     * Run a callback as a given tenant, then restore whatever was set before.
     * Queue jobs use this so one job's tenant never leaks into the next.
     */
    public function run(string $tenantId, Closure $callback): mixed
    {
        $previous = [$this->currentTenantId, $this->tenant, $this->member];

        $this->setTenantId($tenantId);

        try {
            $result = $callback();
        } catch (Throwable $e) {
            // If the callback's query aborted the surrounding transaction,
            // restoring the setting fails too. Rolling back that transaction
            // undoes our set_config anyway, so keep the original error.
            try {
                $this->restore($previous);
            } catch (QueryException) {
                [$this->currentTenantId, $this->tenant, $this->member] = $previous;
            }

            throw $e;
        }

        $this->restore($previous);

        return $result;
    }

    public function clear(): void
    {
        $this->setTenantId(null);
        $this->setUser(null);
    }

    private function restore(array $previous): void
    {
        [$id, $tenant, $member] = $previous;
        $this->setTenantId($id);
        $this->tenant = $tenant;
        $this->member = $member;
    }

    private function setDatabaseSetting(string $name, ?string $value): void
    {
        // Session-level (not transaction-local) so it holds across the whole
        // request; clear() resets it before the connection is reused.
        DB::select('SELECT set_config(?, ?, false)', [$name, $value ?? '']);
    }
}

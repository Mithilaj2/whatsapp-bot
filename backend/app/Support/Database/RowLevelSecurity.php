<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Helpers for migrations that put a table behind PostgreSQL row-level security.
 *
 * Policies compare tenant_id with app_current_tenant_id(), which reads the
 * app.tenant_id setting that TenantContext sets for each request or job. When
 * no tenant is set the function returns NULL, so the table looks empty and
 * inserts are rejected. FORCE applies the policies to the table owner too.
 */
class RowLevelSecurity
{
    /** Standard policy: every row belongs to exactly one tenant. */
    public static function enableForTenantTable(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(<<<SQL
            CREATE POLICY tenant_isolation ON {$table}
                USING (tenant_id = app_current_tenant_id())
                WITH CHECK (tenant_id = app_current_tenant_id())
        SQL);
    }

    public static function enable(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    public static function appRole(): string
    {
        return config('database.app_role');
    }
}

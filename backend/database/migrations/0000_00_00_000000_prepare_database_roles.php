<?php

use App\Support\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Runs first, as the schema owner. Every table created after this is readable
 * and writable by the app role, and the helper functions the row-level
 * security policies use exist before any policy refers to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $app = RowLevelSecurity::appRole();

        DB::statement("GRANT USAGE ON SCHEMA public TO {$app}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$app}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {$app}");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_current_tenant_id() RETURNS uuid
            LANGUAGE sql STABLE AS
            $$ SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_current_user_id() RETURNS uuid
            LANGUAGE sql STABLE AS
            $$ SELECT NULLIF(current_setting('app.user_id', true), '')::uuid $$
        SQL);
    }

    public function down(): void
    {
        $app = RowLevelSecurity::appRole();

        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM {$app}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM {$app}");
        DB::statement('DROP FUNCTION IF EXISTS app_current_tenant_id()');
        DB::statement('DROP FUNCTION IF EXISTS app_current_user_id()');
    }
};

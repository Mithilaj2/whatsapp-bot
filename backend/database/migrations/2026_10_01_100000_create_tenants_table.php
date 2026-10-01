<?php

use App\Support\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('plan_id')->nullable();
            $table->string('status')->default('active');
            $table->string('timezone')->default('Asia/Kolkata');
            $table->string('data_region')->default('ap-south-1');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tenant_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->uuid('team_id')->nullable();
            $table->unsignedInteger('max_chats')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index('user_id');
        });

        // A user may see their own memberships in every business (to pick one
        // after login), but may only create or change memberships inside the
        // business currently set as the tenant.
        RowLevelSecurity::enable('tenant_members');
        DB::statement(<<<'SQL'
            CREATE POLICY members_select ON tenant_members FOR SELECT
                USING (tenant_id = app_current_tenant_id() OR user_id = app_current_user_id())
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY members_insert ON tenant_members FOR INSERT
                WITH CHECK (tenant_id = app_current_tenant_id())
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY members_update ON tenant_members FOR UPDATE
                USING (tenant_id = app_current_tenant_id())
                WITH CHECK (tenant_id = app_current_tenant_id())
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY members_delete ON tenant_members FOR DELETE
                USING (tenant_id = app_current_tenant_id())
        SQL);

        // Same shape for the tenant itself: visible if it is the current
        // tenant or the current user is an active member of it.
        RowLevelSecurity::enable('tenants');
        DB::statement(<<<'SQL'
            CREATE POLICY tenants_select ON tenants FOR SELECT
                USING (
                    id = app_current_tenant_id()
                    OR EXISTS (
                        SELECT 1 FROM tenant_members m
                        WHERE m.tenant_id = tenants.id
                          AND m.user_id = app_current_user_id()
                          AND m.status = 'active'
                    )
                )
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY tenants_insert ON tenants FOR INSERT
                WITH CHECK (id = app_current_tenant_id())
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY tenants_update ON tenants FOR UPDATE
                USING (id = app_current_tenant_id())
                WITH CHECK (id = app_current_tenant_id())
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY tenants_delete ON tenants FOR DELETE
                USING (id = app_current_tenant_id())
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_members');
        Schema::dropIfExists('tenants');
    }
};

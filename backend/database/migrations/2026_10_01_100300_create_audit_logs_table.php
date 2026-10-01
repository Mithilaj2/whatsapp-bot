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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Null for events outside any business, such as a login.
            $table->foreignUuid('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->uuid('entity_id')->nullable();
            $table->jsonb('before_json')->nullable();
            $table->jsonb('after_json')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('at')->useCurrent();

            $table->index(['tenant_id', 'at']);
        });

        RowLevelSecurity::enable('audit_logs');
        DB::statement(<<<'SQL'
            CREATE POLICY audit_select ON audit_logs FOR SELECT
                USING (tenant_id = app_current_tenant_id())
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY audit_insert ON audit_logs FOR INSERT
                WITH CHECK (tenant_id IS NULL OR tenant_id = app_current_tenant_id())
        SQL);

        // Append-only for the app.
        $app = RowLevelSecurity::appRole();
        DB::statement("REVOKE UPDATE, DELETE ON audit_logs FROM {$app}");
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

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
        // Contacts belong to the tenant, keyed by Meta's business-scoped user
        // id (BSUID). The phone number may be missing from webhooks.
        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('bsuid')->nullable();
            $table->string('parent_bsuid')->nullable();
            $table->string('wa_phone')->nullable();
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->jsonb('attributes_json')->nullable();
            $table->string('language')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'last_inbound_at']);
        });
        DB::statement('CREATE UNIQUE INDEX contacts_tenant_bsuid_unique ON contacts (tenant_id, bsuid) WHERE bsuid IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX contacts_tenant_phone_unique ON contacts (tenant_id, wa_phone) WHERE wa_phone IS NOT NULL');
        RowLevelSecurity::enableForTenantTable('contacts');

        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('phone_number_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('open');
            $table->string('owner_type')->default('human'); // human | bot | ai
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('priority')->default('normal');
            // End of the 24-hour customer service window: free-form messages
            // can be sent until then, templates only after.
            $table->timestamp('csw_expires_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'last_message_at']);
        });
        DB::statement("CREATE UNIQUE INDEX conversations_one_open ON conversations (tenant_id, contact_id, phone_number_id) WHERE status = 'open'");
        RowLevelSecurity::enableForTenantTable('conversations');

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction'); // in | out
            $table->string('wamid')->nullable()->unique();
            $table->string('type');
            $table->jsonb('content_json');
            $table->string('media_key')->nullable();
            $table->string('status');
            $table->timestamp('status_at')->nullable();
            $table->string('error_code')->nullable();
            $table->string('error_title')->nullable();
            $table->string('pricing_category')->nullable();
            $table->boolean('billable')->nullable();
            $table->string('sender_type'); // contact | agent | bot | ai | system
            $table->foreignUuid('sender_id')->nullable()->constrained('users')->nullOnDelete();
            // Meta's timestamp for inbound messages, send time for outbound.
            // Order conversations by this, not by insert order: webhooks can
            // arrive out of order.
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['tenant_id', 'conversation_id', 'sent_at']);
        });
        RowLevelSecurity::enableForTenantTable('messages');

        // Raw webhook log, platform level (no tenant yet when it arrives).
        // Short retention: see the webhooks:prune command.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestamp('received_at')->useCurrent();
            $table->string('object')->nullable();
            $table->string('waba_id')->nullable();
            $table->jsonb('payload')->nullable();
            $table->boolean('signature_ok');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();

            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('contacts');
    }
};

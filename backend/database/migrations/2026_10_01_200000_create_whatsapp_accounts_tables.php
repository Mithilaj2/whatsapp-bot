<?php

use App\Support\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Today's WABA. Meta is renaming it the Messaging Account and adding a
        // per-number WhatsApp Account (mandatory by H1 2028), so the number
        // table already has a nullable whatsapp_account_id.
        Schema::create('messaging_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('waba_id')->unique();
            $table->string('name')->nullable();
            $table->string('currency', 3)->default('INR');
            $table->string('timezone')->nullable();
            // The client's business token, encrypted by TokenVault. Never
            // logged, never sent to a browser.
            $table->text('token_ciphertext')->nullable();
            $table->string('token_key_id')->nullable();
            $table->timestamp('token_invalid_at')->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index('tenant_id');
        });
        RowLevelSecurity::enableForTenantTable('messaging_accounts');

        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('messaging_account_id')->constrained()->cascadeOnDelete();
            $table->string('phone_number_id')->unique();
            $table->string('whatsapp_account_id')->nullable();
            $table->string('display_number')->nullable();
            $table->string('display_name')->nullable();
            $table->string('quality')->nullable();
            $table->string('messaging_limit')->nullable();
            $table->text('pin_ciphertext')->nullable();
            $table->boolean('is_coexistence')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index('tenant_id');
        });
        RowLevelSecurity::enableForTenantTable('phone_numbers');

        // Webhooks arrive before we know the tenant, and the tenant tables
        // above are invisible until a tenant is set. This small table maps a
        // Meta id to its tenant. It holds no secrets and no customer data.
        Schema::create('meta_routes', function (Blueprint $table) {
            $table->string('kind'); // waba | phone_number
            $table->string('meta_id');
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['kind', 'meta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_routes');
        Schema::dropIfExists('phone_numbers');
        Schema::dropIfExists('messaging_accounts');
    }
};

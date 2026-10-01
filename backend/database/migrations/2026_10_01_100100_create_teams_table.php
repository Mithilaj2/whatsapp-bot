<?php

use App\Support\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->uuid('business_hours_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        RowLevelSecurity::enableForTenantTable('teams');

        Schema::table('tenant_members', function (Blueprint $table) {
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_members', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
        });
        Schema::dropIfExists('teams');
    }
};

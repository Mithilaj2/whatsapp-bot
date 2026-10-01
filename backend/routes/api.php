<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\PhoneNumberController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Meta calls this for every client; the signature, not a login, authenticates it.
Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive']);

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth');
});

Route::middleware(['auth:sanctum', 'db.user'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::post('tenants', [TenantController::class, 'store']);

    // Everything below acts inside one business, chosen by the X-Tenant-Id header.
    Route::middleware('tenant')->group(function () {
        Route::get('tenant', [TenantController::class, 'show']);
        Route::patch('tenant', [TenantController::class, 'update'])->middleware('tenant.role:owner,admin');

        Route::get('members', [MemberController::class, 'index']);
        Route::patch('members/{member}', [MemberController::class, 'update'])->middleware('tenant.role:owner,admin');

        Route::get('teams', [TeamController::class, 'index']);
        Route::post('teams', [TeamController::class, 'store'])->middleware('tenant.role:owner,admin,supervisor');
        Route::delete('teams/{team}', [TeamController::class, 'destroy'])->middleware('tenant.role:owner,admin,supervisor');

        Route::get('phone-numbers', [PhoneNumberController::class, 'index']);
        Route::get('conversations', [ConversationController::class, 'index']);
        Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages']);
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'send'])
            ->middleware('tenant.role:owner,admin,supervisor,agent');
    });
});

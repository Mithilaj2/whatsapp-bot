<?php

namespace Tests\Feature;

use App\Models\MessagingAccount;
use App\Models\MetaRoute;
use App\Models\PhoneNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\TenantProvisioner;
use App\Services\WhatsApp\TokenVault;
use App\Services\WhatsApp\WebhookSignature;
use Illuminate\Testing\TestResponse;

trait WhatsAppTestHelpers
{
    protected string $appSecret = 'test-app-secret';

    protected function configureWhatsApp(): void
    {
        config([
            'services.whatsapp.app_secret' => $this->appSecret,
            'services.whatsapp.webhook_verify_token' => 'verify-me',
            'services.whatsapp.graph_url' => 'https://graph.test',
        ]);
    }

    /** A business with one connected number. */
    protected function businessWithNumber(string $name, string $phoneNumberId, string $wabaId): array
    {
        $owner = User::factory()->create(['password' => 'correct-horse-battery']);
        $tenant = app(TenantProvisioner::class)->create($owner, ['name' => $name]);

        $number = app(TenantContext::class)->run($tenant->id, function () use ($tenant, $phoneNumberId, $wabaId) {
            $sealed = app(TokenVault::class)->encrypt("token-for-{$wabaId}");
            $account = MessagingAccount::create([
                'waba_id' => $wabaId,
                'token_ciphertext' => $sealed['ciphertext'],
                'token_key_id' => $sealed['key_id'],
            ]);
            MetaRoute::create(['kind' => MetaRoute::PHONE_NUMBER, 'meta_id' => $phoneNumberId, 'tenant_id' => $tenant->id]);
            MetaRoute::create(['kind' => MetaRoute::WABA, 'meta_id' => $wabaId, 'tenant_id' => $tenant->id]);

            return PhoneNumber::create([
                'messaging_account_id' => $account->id,
                'phone_number_id' => $phoneNumberId,
                'display_number' => '+91 98765 00000',
            ]);
        });

        return [$owner, $tenant, $number];
    }

    protected function postWebhook(array $payload, ?string $secret = null): TestResponse
    {
        $raw = json_encode($payload);

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => WebhookSignature::sign($raw, $secret ?? $this->appSecret),
        ], $raw);
    }

    protected function inboundPayload(string $phoneNumberId, string $wamid, string $from, string $text, ?int $timestamp = null, string $name = 'Ravi'): array
    {
        return $this->messagesChange($phoneNumberId, [
            'contacts' => [['profile' => ['name' => $name], 'wa_id' => $from]],
            'messages' => [[
                'from' => $from,
                'id' => $wamid,
                'timestamp' => (string) ($timestamp ?? time()),
                'type' => 'text',
                'text' => ['body' => $text],
            ]],
        ]);
    }

    protected function statusPayload(string $phoneNumberId, string $wamid, string $status, int $timestamp, array $extra = []): array
    {
        return $this->messagesChange($phoneNumberId, [
            'statuses' => [array_merge([
                'id' => $wamid,
                'status' => $status,
                'timestamp' => (string) $timestamp,
                'recipient_id' => '919800000001',
            ], $extra)],
        ]);
    }

    private function messagesChange(string $phoneNumberId, array $value): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA',
                'changes' => [[
                    'field' => 'messages',
                    'value' => array_merge([
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '919876500000', 'phone_number_id' => $phoneNumberId],
                    ], $value),
                ]],
            ]],
        ];
    }

    protected function inTenant(Tenant $tenant, \Closure $callback): mixed
    {
        return app(TenantContext::class)->run($tenant->id, $callback);
    }
}

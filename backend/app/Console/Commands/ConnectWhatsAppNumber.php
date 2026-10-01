<?php

namespace App\Console\Commands;

use App\Models\MessagingAccount;
use App\Models\MetaRoute;
use App\Models\PhoneNumber;
use App\Models\Tenant;
use App\Services\TenantContext;
use App\Services\WhatsApp\GraphApiException;
use App\Services\WhatsApp\GraphClient;
use App\Services\WhatsApp\TokenVault;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Connects a WhatsApp number to a business by hand, for testing with Meta's
 * test number before Embedded Signup (phase 3) exists.
 *
 * The token is read from WHATSAPP_ACCESS_TOKEN or asked for, never passed as
 * an option, so it stays out of shell history.
 */
class ConnectWhatsAppNumber extends Command
{
    protected $signature = 'whatsapp:connect
        {tenant : Business id}
        {--waba= : WhatsApp Business Account id}
        {--phone-number-id= : Phone number id from API Setup}
        {--no-subscribe : Do not subscribe the app to the account\'s webhooks}';

    protected $description = 'Connect a WhatsApp Cloud API number to a business (testing before Embedded Signup)';

    public function handle(TenantContext $context, GraphClient $graph, TokenVault $vault): int
    {
        $tenantId = $this->argument('tenant');
        $wabaId = (string) $this->option('waba');
        $phoneNumberId = (string) $this->option('phone-number-id');
        $token = env('WHATSAPP_ACCESS_TOKEN') ?: $this->secret('Access token');

        if ($wabaId === '' || $phoneNumberId === '' || ! $token) {
            $this->error('Pass --waba and --phone-number-id, and an access token.');

            return self::INVALID;
        }

        try {
            $info = $graph->getPhoneNumber($phoneNumberId, $token);
            if (! $this->option('no-subscribe')) {
                $graph->subscribeApp($wabaId, $token);
            }
        } catch (GraphApiException $e) {
            $this->error("Meta rejected the request: {$e->getMessage()}");

            return self::FAILURE;
        }

        $context->run($tenantId, function () use ($tenantId, $wabaId, $phoneNumberId, $token, $info, $vault) {
            if (Tenant::find($tenantId) === null) {
                throw new \InvalidArgumentException('No business with that id.');
            }

            DB::transaction(function () use ($tenantId, $wabaId, $phoneNumberId, $token, $info, $vault) {
                $sealed = $vault->encrypt($token);
                $account = MessagingAccount::updateOrCreate(['waba_id' => $wabaId], [
                    'token_ciphertext' => $sealed['ciphertext'],
                    'token_key_id' => $sealed['key_id'],
                    'token_invalid_at' => null,
                    'subscribed_at' => $this->option('no-subscribe') ? null : now(),
                    'status' => 'active',
                ]);

                PhoneNumber::updateOrCreate(['phone_number_id' => $phoneNumberId], [
                    'messaging_account_id' => $account->id,
                    'display_number' => $info['display_phone_number'] ?? null,
                    'display_name' => $info['verified_name'] ?? null,
                    'quality' => $info['quality_rating'] ?? null,
                    'messaging_limit' => $info['messaging_limit_tier'] ?? null,
                    'status' => 'active',
                ]);

                MetaRoute::updateOrCreate(['kind' => MetaRoute::WABA, 'meta_id' => $wabaId], ['tenant_id' => $tenantId]);
                MetaRoute::updateOrCreate(['kind' => MetaRoute::PHONE_NUMBER, 'meta_id' => $phoneNumberId], ['tenant_id' => $tenantId]);
            });
        });

        $this->info('Connected '.($info['display_phone_number'] ?? $phoneNumberId).' ('.($info['verified_name'] ?? 'no name').').');

        return self::SUCCESS;
    }
}

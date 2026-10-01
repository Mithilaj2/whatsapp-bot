<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\TenantContext;
use App\Services\WhatsApp\GraphApiException;
use App\Services\WhatsApp\GraphClient;
use App\Services\WhatsApp\TokenVault;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

/**
 * Sends one queued outbound message with the client's own token. The only
 * place a business token is decrypted.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public string $tenantId,
        public string $messageId,
        public string $phoneNumberId,
    ) {
        $this->onQueue('outbound');
    }

    /** Stay under the phone number's throughput (see AppServiceProvider). */
    public function middleware(): array
    {
        return [new RateLimited('whatsapp-send')];
    }

    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    public function handle(TenantContext $context, GraphClient $graph, TokenVault $vault): void
    {
        $context->run($this->tenantId, function () use ($graph, $vault) {
            $message = Message::with('conversation.contact', 'conversation.phoneNumber.messagingAccount')
                ->find($this->messageId);

            if ($message === null || $message->status !== 'queued') {
                return;
            }

            $number = $message->conversation->phoneNumber;
            $account = $number->messagingAccount;

            if ($account->token_ciphertext === null || $account->token_invalid_at !== null) {
                $this->markFailed($message, 'token', 'This WhatsApp account needs to be reconnected.');

                return;
            }

            $token = $vault->decrypt($account->token_ciphertext, $account->token_key_id);

            try {
                $wamid = $graph->sendMessage($number->phone_number_id, $token, [
                    'recipient_type' => 'individual',
                    'to' => $message->conversation->contact->wa_phone,
                    'type' => $message->type,
                    $message->type => $message->content_json[$message->type],
                ]);
            } catch (GraphApiException $e) {
                if ($e->tokenIsInvalid()) {
                    $account->forceFill(['token_invalid_at' => now()])->save();
                }
                if ($e->isRetryable() && $this->attempts() < $this->tries) {
                    throw $e;
                }
                $this->markFailed($message, (string) $e->metaCode, $e->windowClosed()
                    ? 'More than 24 hours since the customer last wrote. Send a template instead.'
                    : ($e->metaTitle ?? $e->getMessage()));

                return;
            }

            $message->forceFill([
                'wamid' => $wamid,
                'status' => 'accepted',
                'status_at' => now(),
            ])->save();
        });
    }

    public function failed(?Throwable $e): void
    {
        app(TenantContext::class)->run($this->tenantId, function () use ($e) {
            $message = Message::find($this->messageId);
            if ($message !== null && $message->status === 'queued') {
                $this->markFailed($message, $e instanceof GraphApiException ? (string) $e->metaCode : 'error', 'Could not send. Please try again.');
            }
        });
    }

    private function markFailed(Message $message, string $code, string $title): void
    {
        $message->forceFill([
            'status' => 'failed',
            'status_at' => now(),
            'error_code' => $code,
            'error_title' => $title,
        ])->save();
    }
}

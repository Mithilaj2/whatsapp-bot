<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\WhatsApp\UnknownMessageStatus;
use App\Services\WhatsApp\WebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public string $webhookEventId)
    {
        $this->onQueue('webhooks');
    }

    /** Back off: a status can arrive before its outbound message is saved. */
    public function backoff(): array
    {
        return [5, 15, 30, 60, 120];
    }

    public function handle(WebhookProcessor $processor): void
    {
        $event = WebhookEvent::find($this->webhookEventId);
        if ($event === null || $event->processed_at !== null) {
            return;
        }

        try {
            $processor->process($event->payload ?? []);
        } catch (UnknownMessageStatus $e) {
            $event->forceFill(['error' => $e->getMessage()])->save();
            $this->release($this->backoff()[min($this->attempts() - 1, 4)]);

            return;
        }

        $event->forceFill(['processed_at' => now(), 'error' => null])->save();
    }

    public function failed(?Throwable $e): void
    {
        WebhookEvent::whereKey($this->webhookEventId)->update(['error' => $e?->getMessage()]);
    }
}

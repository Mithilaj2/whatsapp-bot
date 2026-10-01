<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\TenantContext;

/**
 * Records an outbound message and queues it. Callers never talk to Meta
 * directly; the queue applies retries and the per-number rate limit.
 */
class MessageSender
{
    public function __construct(private TenantContext $context) {}

    public function text(Conversation $conversation, string $body, ?string $senderId, string $senderType = 'agent'): Message
    {
        return $this->queue($conversation, 'text', ['text' => ['body' => $body, 'preview_url' => false]], $senderId, $senderType);
    }

    public function template(Conversation $conversation, string $name, string $language, array $components, ?string $senderId, string $senderType = 'agent'): Message
    {
        $template = ['name' => $name, 'language' => ['code' => $language]];
        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->queue($conversation, 'template', ['template' => $template], $senderId, $senderType);
    }

    private function queue(Conversation $conversation, string $type, array $content, ?string $senderId, string $senderType): Message
    {
        $message = $conversation->messages()->create([
            'direction' => Message::OUT,
            'type' => $type,
            'content_json' => $content,
            'status' => 'queued',
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'sent_at' => now(),
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        SendWhatsAppMessage::dispatch(
            $this->context->tenantId(),
            $message->id,
            $conversation->phoneNumber->phone_number_id,
        )->afterCommit();

        return $message;
    }
}

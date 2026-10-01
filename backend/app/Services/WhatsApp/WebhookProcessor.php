<?php

namespace App\Services\WhatsApp;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MetaRoute;
use App\Models\PhoneNumber;
use App\Services\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns one Cloud API webhook payload into contacts, conversations, messages
 * and status updates, each inside the tenant that owns the phone number.
 *
 * Delivery is at-least-once and unordered, so everything here is idempotent:
 * messages are unique by wamid and statuses only move forward.
 */
class WebhookProcessor
{
    public function __construct(private TenantContext $context) {}

    public function process(array $payload): void
    {
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) === 'messages') {
                    $this->handleMessagesChange($change['value'] ?? []);
                }
                // Template, quality and account updates are handled in later phases.
            }
        }
    }

    private function handleMessagesChange(array $value): void
    {
        $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
        $tenantId = $phoneNumberId !== '' ? MetaRoute::tenantFor(MetaRoute::PHONE_NUMBER, $phoneNumberId) : null;

        if ($tenantId === null) {
            Log::warning('WhatsApp webhook for an unknown phone number', ['phone_number_id' => $phoneNumberId]);

            return;
        }

        $this->context->run($tenantId, function () use ($value, $phoneNumberId) {
            $number = PhoneNumber::where('phone_number_id', $phoneNumberId)->firstOrFail();
            $profiles = $this->contactProfiles($value['contacts'] ?? []);

            foreach ($value['messages'] ?? [] as $message) {
                DB::transaction(fn () => $this->storeInbound($number, $message, $profiles));
            }

            foreach ($value['statuses'] ?? [] as $status) {
                $this->applyStatus($status);
            }
        });
    }

    /**
     * Index the contacts block by sender id. Meta sends the phone as wa_id
     * and, since April 2026, a business-scoped user id. We read it from
     * user_id; check the field name against Meta's webhook reference.
     */
    private function contactProfiles(array $contacts): array
    {
        $byKey = [];
        foreach ($contacts as $c) {
            $profile = [
                'wa_phone' => $c['wa_id'] ?? null,
                'bsuid' => $c['user_id'] ?? null,
                'name' => $c['profile']['name'] ?? null,
                'username' => $c['profile']['username'] ?? null,
            ];
            foreach (['wa_phone', 'bsuid'] as $key) {
                if ($profile[$key] !== null) {
                    $byKey[$profile[$key]] = $profile;
                }
            }
        }

        return $byKey;
    }

    private function storeInbound(PhoneNumber $number, array $message, array $profiles): void
    {
        $wamid = $message['id'] ?? null;
        if ($wamid === null || Message::where('wamid', $wamid)->exists()) {
            return;
        }

        $sender = $message['from_user_id'] ?? $message['from'] ?? null;
        $profile = $profiles[$sender] ?? ['wa_phone' => $message['from'] ?? null, 'bsuid' => $message['from_user_id'] ?? null];
        $sentAt = Carbon::createFromTimestamp((int) ($message['timestamp'] ?? time()));

        $contact = $this->upsertContact($profile, $sentAt);
        $conversation = $this->openConversation($contact, $number);

        // Insert-or-ignore so a duplicate delivered concurrently is harmless.
        $inserted = DB::table('messages')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->context->tenantId(),
            'conversation_id' => $conversation->id,
            'direction' => Message::IN,
            'wamid' => $wamid,
            'type' => $message['type'] ?? 'unknown',
            'content_json' => json_encode($message),
            'status' => 'received',
            'sender_type' => 'contact',
            'sent_at' => $sentAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return;
        }

        // Each customer message opens (or extends) the 24-hour window. Keep
        // the latest, since an older message may be processed after a newer one.
        $windowEnd = $sentAt->copy()->addDay();
        $conversation->forceFill([
            'csw_expires_at' => max($conversation->csw_expires_at ?? $windowEnd, $windowEnd),
            'last_message_at' => max($conversation->last_message_at ?? $sentAt, $sentAt),
        ])->save();
    }

    private function upsertContact(array $profile, Carbon $sentAt): Contact
    {
        $query = Contact::query()->lockForUpdate();
        $contact = null;
        if (! empty($profile['bsuid'])) {
            $contact = (clone $query)->where('bsuid', $profile['bsuid'])->first();
        }
        if ($contact === null && ! empty($profile['wa_phone'])) {
            $contact = (clone $query)->where('wa_phone', $profile['wa_phone'])->first();
        }

        $contact ??= new Contact;
        $contact->fill(array_filter([
            'bsuid' => $profile['bsuid'] ?? null,
            'wa_phone' => $profile['wa_phone'] ?? null,
            'name' => $profile['name'] ?? null,
            'username' => $profile['username'] ?? null,
        ], fn ($v) => $v !== null));

        if ($contact->last_inbound_at === null || $sentAt->greaterThan($contact->last_inbound_at)) {
            $contact->last_inbound_at = $sentAt;
        }
        $contact->save();

        return $contact;
    }

    private function openConversation(Contact $contact, PhoneNumber $number): Conversation
    {
        return Conversation::where('contact_id', $contact->id)
            ->where('phone_number_id', $number->id)
            ->where('status', 'open')
            ->lockForUpdate()
            ->first()
            ?? Conversation::create([
                'contact_id' => $contact->id,
                'phone_number_id' => $number->id,
                'status' => 'open',
            ]);
    }

    private function applyStatus(array $status): void
    {
        $wamid = $status['id'] ?? null;
        $new = $status['status'] ?? null;
        if ($wamid === null || $new === null) {
            return;
        }

        DB::transaction(function () use ($wamid, $new, $status) {
            $message = Message::where('wamid', $wamid)->lockForUpdate()->first();
            if ($message === null) {
                throw new UnknownMessageStatus("No message with wamid {$wamid} yet");
            }

            $changes = [];
            if ($message->canMoveTo($new)) {
                $changes['status'] = $new;
                $changes['status_at'] = Carbon::createFromTimestamp((int) ($status['timestamp'] ?? time()));
            }
            if ($new === 'failed' && isset($status['errors'][0])) {
                $changes['error_code'] = (string) ($status['errors'][0]['code'] ?? '');
                $changes['error_title'] = $status['errors'][0]['title'] ?? null;
            }
            if (isset($status['pricing'])) {
                $changes['pricing_category'] = $status['pricing']['category'] ?? null;
                $changes['billable'] = $status['pricing']['billable'] ?? null;
            }

            if ($changes !== []) {
                $message->forceFill($changes)->save();
            }
        });
    }
}

<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Tests\RefreshesTenantDatabase;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshesTenantDatabase, WhatsAppTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureWhatsApp();
    }

    public function test_meta_can_verify_the_callback_url(): void
    {
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345')
            ->assertOk()->assertSee('12345');
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')
            ->assertForbidden();
    }

    public function test_a_bad_signature_is_rejected_and_nothing_is_stored(): void
    {
        [, $tenant] = $this->businessWithNumber('Shop', 'PN1', 'WABA1');

        $this->postWebhook($this->inboundPayload('PN1', 'wamid.1', '919800000001', 'hi'), 'wrong-secret')
            ->assertUnauthorized();

        $this->assertSame(0, $this->inTenant($tenant, fn () => Message::count()));
        $this->assertNull(WebhookEvent::where('signature_ok', false)->value('payload'));
    }

    public function test_an_inbound_message_creates_contact_conversation_and_message(): void
    {
        [, $tenant] = $this->businessWithNumber('Shop', 'PN1', 'WABA1');
        $sentAt = now()->subMinutes(5)->timestamp;

        $this->postWebhook($this->inboundPayload('PN1', 'wamid.1', '919800000001', 'Hi, price?', $sentAt))
            ->assertOk()->assertSee('EVENT_RECEIVED');

        $this->inTenant($tenant, function () use ($sentAt) {
            $contact = Contact::sole();
            $this->assertSame('919800000001', $contact->wa_phone);
            $this->assertSame('Ravi', $contact->name);

            $conversation = Conversation::sole();
            $this->assertTrue($conversation->windowIsOpen());
            $this->assertSame($sentAt + 86400, $conversation->csw_expires_at->timestamp);

            $message = Message::sole();
            $this->assertSame('in', $message->direction);
            $this->assertSame('Hi, price?', $message->content_json['text']['body']);
        });
        $this->assertNotNull(WebhookEvent::sole()->processed_at);
    }

    public function test_duplicate_deliveries_are_stored_once(): void
    {
        [, $tenant] = $this->businessWithNumber('Shop', 'PN1', 'WABA1');
        $payload = $this->inboundPayload('PN1', 'wamid.dup', '919800000001', 'hello');

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertSame(1, $this->inTenant($tenant, fn () => Message::count()));
        $this->assertSame(1, $this->inTenant($tenant, fn () => Contact::count()));
    }

    public function test_an_older_message_arriving_late_does_not_shorten_the_window(): void
    {
        [, $tenant] = $this->businessWithNumber('Shop', 'PN1', 'WABA1');
        $newer = now()->subMinutes(1)->timestamp;
        $older = now()->subHours(3)->timestamp;

        $this->postWebhook($this->inboundPayload('PN1', 'wamid.new', '919800000001', 'second', $newer));
        $this->postWebhook($this->inboundPayload('PN1', 'wamid.old', '919800000001', 'first', $older));

        $this->inTenant($tenant, function () use ($newer) {
            $conversation = Conversation::sole();
            $this->assertSame($newer + 86400, $conversation->csw_expires_at->timestamp);
            $this->assertSame(
                ['first', 'second'],
                $conversation->messages()->orderBy('sent_at')->get()->map(fn ($m) => $m->content_json['text']['body'])->all(),
            );
        });
    }

    public function test_statuses_only_move_forward_even_out_of_order(): void
    {
        [, $tenant] = $this->businessWithNumber('Shop', 'PN1', 'WABA1');
        $this->postWebhook($this->inboundPayload('PN1', 'wamid.in', '919800000001', 'hi'));
        $this->inTenant($tenant, fn () => Message::create([
            'conversation_id' => Conversation::sole()->id,
            'direction' => 'out',
            'wamid' => 'wamid.out',
            'type' => 'text',
            'content_json' => ['text' => ['body' => 'Hello!']],
            'status' => 'accepted',
            'sender_type' => 'agent',
            'sent_at' => now(),
        ]));

        $t = now()->timestamp;
        $this->postWebhook($this->statusPayload('PN1', 'wamid.out', 'read', $t + 2));
        $this->postWebhook($this->statusPayload('PN1', 'wamid.out', 'delivered', $t + 1, [
            'pricing' => ['billable' => false, 'category' => 'service', 'pricing_model' => 'PMP'],
        ]));
        $this->postWebhook($this->statusPayload('PN1', 'wamid.out', 'failed', $t + 3, [
            'errors' => [['code' => 131026, 'title' => 'Message undeliverable']],
        ]));

        $message = $this->inTenant($tenant, fn () => Message::where('wamid', 'wamid.out')->sole());
        $this->assertSame('read', $message->status);
        $this->assertSame('service', $message->pricing_category);
        $this->assertFalse($message->billable);
    }

    public function test_a_failed_status_records_the_error(): void
    {
        [, $tenant] = $this->businessWithNumber('Shop', 'PN1', 'WABA1');
        $this->postWebhook($this->inboundPayload('PN1', 'wamid.in', '919800000001', 'hi'));
        $this->inTenant($tenant, fn () => Message::create([
            'conversation_id' => Conversation::sole()->id,
            'direction' => 'out', 'wamid' => 'wamid.out', 'type' => 'text',
            'content_json' => ['text' => ['body' => 'x']], 'status' => 'sent', 'sender_type' => 'agent', 'sent_at' => now(),
        ]));

        $this->postWebhook($this->statusPayload('PN1', 'wamid.out', 'failed', now()->timestamp, [
            'errors' => [['code' => 131026, 'title' => 'Message undeliverable']],
        ]));

        $message = $this->inTenant($tenant, fn () => Message::where('wamid', 'wamid.out')->sole());
        $this->assertSame('failed', $message->status);
        $this->assertSame('131026', $message->error_code);
    }

    public function test_each_number_routes_to_its_own_business(): void
    {
        [, $shopA] = $this->businessWithNumber('Shop A', 'PN-A', 'WABA-A');
        [, $shopB] = $this->businessWithNumber('Shop B', 'PN-B', 'WABA-B');

        $this->postWebhook($this->inboundPayload('PN-A', 'wamid.a', '919800000001', 'for A'));
        $this->postWebhook($this->inboundPayload('PN-B', 'wamid.b', '919800000001', 'for B'));

        $this->assertSame(['for A'], $this->inTenant($shopA, fn () => Message::get()->map(fn ($m) => $m->content_json['text']['body'])->all()));
        $this->assertSame(['for B'], $this->inTenant($shopB, fn () => Message::get()->map(fn ($m) => $m->content_json['text']['body'])->all()));
        // Same customer, two businesses: two separate contacts.
        $this->assertSame(1, $this->inTenant($shopA, fn () => Contact::count()));
        $this->assertSame(1, $this->inTenant($shopB, fn () => Contact::count()));
    }

    public function test_a_status_for_a_message_not_saved_yet_is_retried_later(): void
    {
        $this->businessWithNumber('Shop', 'PN1', 'WABA1');

        $this->postWebhook($this->statusPayload('PN1', 'wamid.notyet', 'delivered', now()->timestamp))->assertOk();

        $event = WebhookEvent::sole();
        $this->assertNull($event->processed_at);
        $this->assertStringContainsString('wamid.notyet', $event->error);
    }

    public function test_an_unknown_number_is_acknowledged_and_ignored(): void
    {
        $this->postWebhook($this->inboundPayload('PN-UNKNOWN', 'wamid.x', '919800000001', 'hi'))->assertOk();

        $this->assertSame(0, DB::table('messages')->count());
        $this->assertNotNull(WebhookEvent::sole()->processed_at);
    }
}

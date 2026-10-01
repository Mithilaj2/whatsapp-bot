<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessagingAccount;
use App\Models\TenantMember;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\RefreshesTenantDatabase;
use Tests\TestCase;

class WhatsAppSendTest extends TestCase
{
    use RefreshesTenantDatabase, WhatsAppTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureWhatsApp();
    }

    private function conversationWith(string $lastInbound): array
    {
        [$owner, $tenant] = $this->businessWithNumber('Shop', 'PN1', 'WABA1');
        $this->postWebhook($this->inboundPayload('PN1', 'wamid.in', '919800000001', 'hi', strtotime($lastInbound)));
        $conversation = $this->inTenant($tenant, fn () => Conversation::sole());

        return [$owner, $tenant, $conversation];
    }

    public function test_an_agent_reply_is_sent_with_the_clients_token(): void
    {
        Http::fake(['graph.test/*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);
        [$owner, $tenant, $conversation] = $this->conversationWith('-10 minutes');

        $this->asUser($this->tokenFor($owner), $tenant->id)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['type' => 'text', 'text' => 'Hello Ravi'])
            ->assertStatus(202);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.test/v25.0/PN1/messages'
            && $r->hasHeader('Authorization', 'Bearer token-for-WABA1')
            && $r['to'] === '919800000001'
            && $r['text']['body'] === 'Hello Ravi');

        $message = $this->inTenant($tenant, fn () => Message::where('direction', 'out')->sole());
        $this->assertSame('wamid.reply', $message->wamid);
        $this->assertSame('accepted', $message->status);
    }

    public function test_free_text_is_refused_after_the_24_hour_window(): void
    {
        Http::fake();
        [$owner, $tenant, $conversation] = $this->conversationWith('-25 hours');

        $this->asUser($this->tokenFor($owner), $tenant->id)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['type' => 'text', 'text' => 'Hello'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_template_can_be_sent_after_the_window(): void
    {
        Http::fake(['graph.test/*' => Http::response(['messages' => [['id' => 'wamid.tpl']]])]);
        [$owner, $tenant, $conversation] = $this->conversationWith('-25 hours');

        $this->asUser($this->tokenFor($owner), $tenant->id)
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'type' => 'template',
                'template' => ['name' => 'hello_world', 'language' => 'en_US'],
            ])->assertStatus(202);

        Http::assertSent(fn (Request $r) => $r['type'] === 'template' && $r['template']['name'] === 'hello_world');
    }

    public function test_an_expired_token_marks_the_account_and_fails_the_message(): void
    {
        Http::fake(['graph.test/*' => Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]], 401)]);
        [$owner, $tenant, $conversation] = $this->conversationWith('-10 minutes');

        $this->asUser($this->tokenFor($owner), $tenant->id)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['type' => 'text', 'text' => 'Hi']);

        $this->inTenant($tenant, function () {
            $this->assertSame('failed', Message::where('direction', 'out')->sole()->status);
            $this->assertNotNull(MessagingAccount::sole()->token_invalid_at);
        });
    }

    public function test_a_viewer_cannot_send(): void
    {
        Http::fake();
        [, $tenant, $conversation] = $this->conversationWith('-10 minutes');
        $viewer = User::factory()->create(['password' => 'correct-horse-battery']);
        $this->inTenant($tenant, fn () => TenantMember::create(['user_id' => $viewer->id, 'role' => TenantRole::Viewer]));

        $this->asUser($this->tokenFor($viewer), $tenant->id)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['type' => 'text', 'text' => 'Hi'])
            ->assertForbidden();
    }

    public function test_another_business_cannot_read_or_reply_to_a_conversation(): void
    {
        Http::fake();
        [, , $conversation] = $this->conversationWith('-10 minutes');
        [$otherOwner, $other] = $this->businessWithNumber('Other', 'PN2', 'WABA2');
        $token = $this->tokenFor($otherOwner);

        $this->asUser($token, $other->id)->getJson('/api/conversations')->assertOk()->assertJsonCount(0, 'conversations');
        $this->asUser($token, $other->id)->getJson("/api/conversations/{$conversation->id}/messages")->assertNotFound();
        $this->asUser($token, $other->id)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['type' => 'text', 'text' => 'Hi'])
            ->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_the_inbox_lists_conversations_and_messages(): void
    {
        [$owner, $tenant, $conversation] = $this->conversationWith('-10 minutes');
        $token = $this->tokenFor($owner);

        $this->asUser($token, $tenant->id)->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('conversations.0.contact.name', 'Ravi')
            ->assertJsonPath('conversations.0.window_open', true);
        $this->asUser($token, $tenant->id)->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('messages.0.text', 'hi');
    }
}

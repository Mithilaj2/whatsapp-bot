<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\TenantContext;
use App\Services\WhatsApp\MessageSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $conversations = Conversation::with('contact:id,name,wa_phone,bsuid', 'phoneNumber:id,display_number,display_name')
            ->where('status', $request->query('status', 'open'))
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        return response()->json([
            'conversations' => $conversations->map(fn (Conversation $c) => [
                'id' => $c->id,
                'contact' => $c->contact?->only(['id', 'name', 'wa_phone']),
                'phone_number' => $c->phoneNumber?->only(['id', 'display_number', 'display_name']),
                'status' => $c->status,
                'window_open' => $c->windowIsOpen(),
                'csw_expires_at' => $c->csw_expires_at?->toIso8601String(),
                'last_message_at' => $c->last_message_at?->toIso8601String(),
            ]),
        ]);
    }

    public function messages(Conversation $conversation): JsonResponse
    {
        $messages = $conversation->messages()->orderBy('sent_at')->orderBy('created_at')->limit(500)->get();

        return response()->json([
            'window_open' => $conversation->windowIsOpen(),
            'messages' => $messages->map(fn (Message $m) => $this->present($m)),
        ]);
    }

    public function send(Request $request, Conversation $conversation, MessageSender $sender, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['text', 'template'])],
            'text' => ['required_if:type,text', 'string', 'max:4096'],
            'template.name' => ['required_if:type,template', 'string', 'max:512'],
            'template.language' => ['required_if:type,template', 'string', 'max:15'],
            'template.components' => ['sometimes', 'array'],
        ]);

        if (! $conversation->contact->isReachable()) {
            return response()->json(['message' => 'This contact has no phone number on file yet.'], 422);
        }

        if ($data['type'] === 'text') {
            if (! $conversation->windowIsOpen()) {
                return response()->json([
                    'message' => 'More than 24 hours since the customer last wrote. Send a template instead.',
                ], 422);
            }
            $message = $sender->text($conversation, $data['text'], $context->userId());
        } else {
            $message = $sender->template(
                $conversation,
                $data['template']['name'],
                $data['template']['language'],
                $data['template']['components'] ?? [],
                $context->userId(),
            );
        }

        return response()->json(['message' => $this->present($message->fresh())], 202);
    }

    private function present(Message $m): array
    {
        return [
            'id' => $m->id,
            'direction' => $m->direction,
            'type' => $m->type,
            'text' => $this->previewText($m),
            'status' => $m->status,
            'error' => $m->error_title,
            'sender_type' => $m->sender_type,
            'sent_at' => $m->sent_at?->toIso8601String(),
        ];
    }

    private function previewText(Message $m): string
    {
        $c = $m->content_json ?? [];

        return match ($m->type) {
            'text' => $c['text']['body'] ?? '',
            'template' => 'Template: '.($c['template']['name'] ?? ''),
            'interactive' => $c['interactive']['button_reply']['title'] ?? $c['interactive']['list_reply']['title'] ?? '[Interactive reply]',
            'button' => $c['button']['text'] ?? '[Button]',
            'image', 'video', 'audio', 'document', 'sticker' => '['.ucfirst($m->type).']'.(isset($c[$m->type]['caption']) ? ' '.$c[$m->type]['caption'] : ''),
            'location' => '[Location]',
            default => '['.$m->type.']',
        };
    }
}

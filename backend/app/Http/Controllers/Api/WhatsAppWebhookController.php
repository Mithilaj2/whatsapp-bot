<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\WebhookEvent;
use App\Services\WhatsApp\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * The single callback URL for every client's WhatsApp webhooks. It checks
 * the signature, stores the raw payload, queues it and answers 200 at once.
 * It never calls the Graph API.
 */
class WhatsAppWebhookController extends Controller
{
    /** Meta's subscription check when the callback URL is saved. */
    public function verify(Request $request): Response
    {
        $expected = config('services.whatsapp.webhook_verify_token');

        if ($request->query('hub_mode') === 'subscribe'
            && is_string($expected) && $expected !== ''
            && hash_equals($expected, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request): Response
    {
        $raw = $request->getContent();
        $valid = WebhookSignature::isValid(
            $raw,
            $request->header('X-Hub-Signature-256'),
            config('services.whatsapp.app_secret'),
        );

        if (! $valid) {
            // Keep a trace for alerting, but not the unverified body.
            WebhookEvent::create(['signature_ok' => false, 'received_at' => now()]);
            Log::warning('WhatsApp webhook with a bad signature', ['ip' => $request->ip()]);

            return response('Invalid signature', 401);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response('Bad payload', 400);
        }

        $event = WebhookEvent::create([
            'signature_ok' => true,
            'received_at' => now(),
            'object' => $payload['object'] ?? null,
            'waba_id' => $payload['entry'][0]['id'] ?? null,
            'payload' => $payload,
        ]);

        ProcessWhatsAppWebhook::dispatch($event->id);

        return response('EVENT_RECEIVED', 200);
    }
}

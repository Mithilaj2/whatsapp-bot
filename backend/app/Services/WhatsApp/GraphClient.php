<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the WhatsApp Cloud API, pinned to one Graph API version.
 * Never call this from a web request handling a webhook: queue instead.
 */
class GraphClient
{
    /**
     * Send a message. $payload is the body without messaging_product.
     *
     * @return string the new message's wamid
     */
    public function sendMessage(string $phoneNumberId, string $token, array $payload): string
    {
        $body = $this->post($token, "{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            ...$payload,
        ]);

        return $body['messages'][0]['id'];
    }

    public function getPhoneNumber(string $phoneNumberId, string $token): array
    {
        return $this->get($token, $phoneNumberId, [
            'fields' => 'display_phone_number,verified_name,quality_rating,messaging_limit_tier',
        ]);
    }

    public function subscribeApp(string $wabaId, string $token): void
    {
        $this->post($token, "{$wabaId}/subscribed_apps", []);
    }

    private function get(string $token, string $path, array $query = []): array
    {
        return $this->handle($this->request($token)->get($path, $query));
    }

    private function post(string $token, string $path, array $data): array
    {
        return $this->handle($this->request($token)->post($path, $data));
    }

    private function request(string $token): PendingRequest
    {
        $base = rtrim(config('services.whatsapp.graph_url'), '/').'/'.config('services.whatsapp.graph_version');

        return Http::baseUrl($base)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->connectTimeout(5);
    }

    private function handle($response): array
    {
        $body = $response->json() ?? [];

        if ($response->failed()) {
            throw GraphApiException::fromResponse($response->status(), $body);
        }

        return $body;
    }
}

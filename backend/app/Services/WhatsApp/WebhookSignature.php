<?php

namespace App\Services\WhatsApp;

/**
 * Meta signs each webhook POST with HMAC-SHA256 of the raw body, keyed by
 * our App Secret, in the X-Hub-Signature-256 header as "sha256=<hex>".
 */
class WebhookSignature
{
    public static function isValid(string $rawBody, ?string $header, ?string $appSecret): bool
    {
        if ($appSecret === null || $appSecret === '' || $header === null || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expected, substr($header, 7));
    }

    public static function sign(string $rawBody, string $appSecret): string
    {
        return 'sha256='.hash_hmac('sha256', $rawBody, $appSecret);
    }
}

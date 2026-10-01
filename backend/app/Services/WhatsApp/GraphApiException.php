<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * An error response from Meta's Graph API, with enough classification for
 * the sender to decide whether to retry.
 */
class GraphApiException extends RuntimeException
{
    // Throttling and temporary errors: retry later.
    private const RETRYABLE = [1, 2, 4, 17, 341, 80007, 130429, 131000, 131016, 131056, 133004];

    // The token is expired or revoked: stop using it.
    private const TOKEN_INVALID = [190];

    public function __construct(
        public readonly int $httpStatus,
        public readonly ?int $metaCode,
        public readonly ?string $metaTitle,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(int $status, array $body): self
    {
        $error = $body['error'] ?? [];

        return new self(
            $status,
            isset($error['code']) ? (int) $error['code'] : null,
            $error['error_data']['details'] ?? $error['title'] ?? $error['type'] ?? null,
            $error['message'] ?? "Graph API returned HTTP {$status}",
        );
    }

    public function isRetryable(): bool
    {
        return in_array($this->metaCode, self::RETRYABLE, true) || $this->httpStatus >= 500;
    }

    public function tokenIsInvalid(): bool
    {
        return in_array($this->metaCode, self::TOKEN_INVALID, true);
    }

    /** 131047: more than 24 hours since the customer last wrote; use a template. */
    public function windowClosed(): bool
    {
        return $this->metaCode === 131047;
    }
}

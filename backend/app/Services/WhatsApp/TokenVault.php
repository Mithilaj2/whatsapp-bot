<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts clients' Meta business tokens and two-step PINs.
 *
 * For now this uses Laravel's encrypter (AES-256, keyed by APP_KEY). The
 * plan calls for envelope encryption with a per-tenant data key wrapped by
 * AWS KMS; that replaces this class without changing its callers, and
 * token_key_id records which key encrypted each value.
 */
class TokenVault
{
    public const KEY_ID = 'app-key-v1';

    public function encrypt(string $plain): array
    {
        return ['ciphertext' => Crypt::encryptString($plain), 'key_id' => self::KEY_ID];
    }

    public function decrypt(string $ciphertext, ?string $keyId = null): string
    {
        return Crypt::decryptString($ciphertext);
    }
}

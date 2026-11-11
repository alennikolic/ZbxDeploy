<?php
// src/Core/Crypto.php

declare(strict_types=1);

namespace ZbxDeploy\Core;

/**
 * Symmetric encryption for anything stored in the database that would give an
 * attacker access to the fleet: SSH private keys, SSH/sudo passwords, Zabbix
 * API tokens.
 *
 * APP_KEY is a base64-encoded 32-byte key kept in .env, outside the database.
 * Losing it means re-entering every credential; leaking it means every
 * credential is compromised. Treat it accordingly.
 */
final class Crypto
{
    private static ?string $key = null;

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $raw = base64_decode(Config::require('APP_KEY'), true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(
                'APP_KEY must be a base64-encoded 32-byte key. Generate one with: php bin/genkey.php'
            );
        }

        self::$key = $raw;

        return self::$key;
    }

    public static function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, self::key());

        return $nonce . $cipher;
    }

    public static function decrypt(string $payload): string
    {
        if (strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Ciphertext is too short to be valid.');
        }

        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());
        if ($plain === false) {
            throw new \RuntimeException('Could not decrypt value: wrong APP_KEY or corrupted data.');
        }

        return $plain;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }
}

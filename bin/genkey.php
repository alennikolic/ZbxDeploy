<?php

declare(strict_types=1);

/**
 * Prints a ready-to-paste APP_KEY line for .env.
 *
 *   docker compose run --rm php php bin/genkey.php
 *
 * The value is single-quoted on purpose: Docker Compose treats $ as the start
 * of a variable reference in .env, and only single quotes switch that off.
 *
 * Back the key up. Without it, every stored credential is unrecoverable.
 */

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "The sodium extension is not available.\n");
    exit(1);
}

$key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

// The line itself goes to stdout so it can be piped or appended directly.
echo "APP_KEY='", $key, "'", PHP_EOL;

fwrite(STDERR, <<<TXT

    Copy the line above into .env, replacing the existing APP_KEY.
    Keep the single quotes. Store a copy somewhere safe - this key decrypts
    every SSH key and password ZbxDeploy holds.

    TXT);

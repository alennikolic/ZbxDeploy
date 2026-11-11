<?php

declare(strict_types=1);

/**
 * Prints a new APP_KEY. Run once when setting up, put the value in .env,
 * and keep a copy somewhere safe: without it, every stored credential is lost.
 *
 *   docker compose run --rm php php bin/genkey.php
 */

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "The sodium extension is not available.\n");
    exit(1);
}

echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), PHP_EOL;

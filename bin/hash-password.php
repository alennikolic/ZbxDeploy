<?php

declare(strict_types=1);

/**
 * Produces the ADMIN_PASSWORD_HASH value for .env.
 *
 *   docker compose run --rm php php bin/hash-password.php
 *
 * The password is read from stdin without echoing where possible, so it does
 * not end up in your shell history.
 */

function readSecret(string $prompt): string
{
    fwrite(STDOUT, $prompt);

    if (stripos(PHP_OS_FAMILY, 'win') === false && is_readable('/dev/tty')) {
        shell_exec('stty -echo 2>/dev/null');
        $value = trim((string) fgets(STDIN));
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);

        return $value;
    }

    return trim((string) fgets(STDIN));
}

$password = readSecret('New password: ');
$confirm = readSecret('Repeat password: ');

if ($password === '') {
    fwrite(STDERR, "Password cannot be empty.\n");
    exit(1);
}

if ($password !== $confirm) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

if (strlen($password) < 12) {
    fwrite(STDERR, "Use at least 12 characters: this account can reach every server in your inventory.\n");
    exit(1);
}

echo PHP_EOL, 'ADMIN_PASSWORD_HASH=', password_hash($password, PASSWORD_DEFAULT), PHP_EOL;

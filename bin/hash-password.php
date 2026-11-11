<?php

declare(strict_types=1);

/**
 * Prints a ready-to-paste ADMIN_PASSWORD_HASH line for .env.
 *
 *   docker compose run --rm php php bin/hash-password.php
 *
 * The value is single-quoted on purpose: a bcrypt hash contains $ characters,
 * which Docker Compose would otherwise read as variable references and replace
 * with empty strings. Only single quotes switch that off.
 *
 * The password is read without echoing, so it stays out of your shell history.
 */

function readSecret(string $prompt): string
{
    fwrite(STDERR, $prompt);

    $hasTty = PHP_OS_FAMILY !== 'Windows' && stream_isatty(STDIN);

    if ($hasTty) {
        shell_exec('stty -echo 2>/dev/null');
        $value = (string) fgets(STDIN);
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDERR, PHP_EOL);
    } else {
        $value = (string) fgets(STDIN);
    }

    return trim($value);
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

$hash = password_hash($password, PASSWORD_DEFAULT);

if (str_contains($hash, "'")) {
    // Not possible with bcrypt, but a future algorithm change must not produce
    // a line that silently breaks .env parsing.
    fwrite(STDERR, "The generated hash contains a single quote; refusing to print an unsafe .env line.\n");
    exit(1);
}

echo "ADMIN_PASSWORD_HASH='", $hash, "'", PHP_EOL;

fwrite(STDERR, <<<TXT

    Copy the line above into .env, replacing the existing ADMIN_PASSWORD_HASH.
    Keep the single quotes, then run: docker compose up -d --force-recreate php worker

    TXT);

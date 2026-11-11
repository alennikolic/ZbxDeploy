<?php
// src/Core/Csrf.php

declare(strict_types=1);

namespace ZbxDeploy\Core;

final class Csrf
{
    private const KEY = 'csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::KEY];
    }

    public static function check(?string $token): bool
    {
        if ($token === null || empty($_SESSION[self::KEY])) {
            return false;
        }

        return hash_equals((string) $_SESSION[self::KEY], $token);
    }

    public static function requireValid(): void
    {
        if (self::check($_POST['_csrf'] ?? null)) {
            return;
        }

        http_response_code(419);
        echo 'CSRF token mismatch. Reload the page and try again.';
        exit;
    }
}

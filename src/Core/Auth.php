<?php
// src/Core/Auth.php

declare(strict_types=1);

namespace ZbxDeploy\Core;

/**
 * Single-operator authentication: username and password hash live in .env.
 * There is deliberately no users table yet - when multi-user and RBAC are
 * needed, this class is the only thing that has to change.
 */
final class Auth
{
    private const SESSION_KEY = 'zbxdeploy_user';

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name('zbxdeploy');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => Config::bool('SESSION_SECURE_COOKIE', false),
        ]);
        session_start();
    }

    public static function attempt(string $username, string $password): bool
    {
        $expectedUser = Config::get('ADMIN_USER', 'admin');
        $hash = Config::get('ADMIN_PASSWORD_HASH');

        if ($hash === null) {
            throw new \RuntimeException(
                'ADMIN_PASSWORD_HASH is not set. Generate one with: php bin/hash-password.php'
            );
        }

        // Compare both factors every time; do not short-circuit on the username.
        $userOk = hash_equals((string) $expectedUser, $username);
        $passOk = password_verify($password, $hash);

        if (!$userOk || !$passOk) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $username;
        $_SESSION['login_time'] = time();

        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return false;
        }

        $maxAge = Config::int('SESSION_LIFETIME_MINUTES', 480) * 60;
        if (isset($_SESSION['login_time']) && (time() - (int) $_SESSION['login_time']) > $maxAge) {
            self::logout();

            return false;
        }

        return true;
    }

    public static function user(): string
    {
        return (string) ($_SESSION[self::SESSION_KEY] ?? 'anonymous');
    }

    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }

        header('Location: /login');
        exit;
    }
}

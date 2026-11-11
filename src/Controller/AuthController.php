<?php
// src/Controller/AuthController.php

declare(strict_types=1);

namespace ZbxDeploy\Controller;

use ZbxDeploy\Core\Auth;
use ZbxDeploy\Core\Audit;
use ZbxDeploy\Core\Csrf;
use ZbxDeploy\Core\Flash;
use ZbxDeploy\Core\View;

final class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            View::redirect('/');
        }

        View::send('login.twig');
    }

    public function login(): void
    {
        Csrf::requireValid();

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        // Slow down trivial brute force without building a lockout table yet.
        usleep(random_int(150_000, 400_000));

        try {
            $ok = Auth::attempt($username, $password);
        } catch (\RuntimeException $e) {
            Flash::error($e->getMessage());
            View::redirect('/login');
        }

        if (!$ok) {
            Audit::record('login.failed', 'user', null, ['username' => $username], $username);
            Flash::error('Invalid username or password.');
            View::redirect('/login');
        }

        Audit::record('login.success', 'user', null, []);
        View::redirect('/');
    }

    public function logout(): void
    {
        Csrf::requireValid();
        Audit::record('logout', 'user', null, []);
        Auth::logout();
        View::redirect('/login');
    }
}

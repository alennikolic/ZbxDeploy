<?php
// public/index.php

declare(strict_types=1);

use ZbxDeploy\Controller\AuthController;
use ZbxDeploy\Controller\CredentialController;
use ZbxDeploy\Controller\DashboardController;
use ZbxDeploy\Controller\HostController;
use ZbxDeploy\Controller\JobController;
use ZbxDeploy\Controller\RoleController;
use ZbxDeploy\Core\Auth;
use ZbxDeploy\Core\Config;
use ZbxDeploy\Core\Router;
use ZbxDeploy\Core\View;

require dirname(__DIR__) . '/vendor/autoload.php';

if (Config::isDebug()) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

Auth::startSession();

$router = new Router();

$router->get('/login', [AuthController::class, 'showLogin'], auth: false);
$router->post('/login', [AuthController::class, 'login'], auth: false);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/', [DashboardController::class, 'index']);

$router->get('/hosts', [HostController::class, 'index']);
$router->post('/hosts', [HostController::class, 'store']);
$router->get('/hosts/{id}', [HostController::class, 'show']);
$router->post('/hosts/{id}/probe', [HostController::class, 'probe']);
$router->post('/hosts/{id}/delete', [HostController::class, 'delete']);
$router->post('/probe-all', [HostController::class, 'probe']);

$router->get('/hosts/{id}/roles/new', [RoleController::class, 'newForm']);
$router->post('/hosts/{id}/roles', [RoleController::class, 'store']);
$router->post('/hosts/{id}/roles/{roleRowId}/delete', [RoleController::class, 'delete']);
$router->post('/hosts/{id}/apply', [RoleController::class, 'apply']);

$router->get('/credentials', [CredentialController::class, 'index']);
$router->post('/credentials', [CredentialController::class, 'store']);
$router->post('/credentials/{id}/delete', [CredentialController::class, 'delete']);

$router->get('/jobs', [JobController::class, 'index']);
$router->get('/jobs/{id}', [JobController::class, 'show']);
$router->get('/jobs/{id}/logs', [JobController::class, 'logs']);

try {
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
} catch (Throwable $e) {
    error_log('[zbxdeploy] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    http_response_code(500);
    View::send('error.twig', [
        'title'   => 'Something went wrong',
        'message' => Config::isDebug()
            ? $e->getMessage() . "\n\n" . $e->getTraceAsString()
            : 'An internal error occurred. Check the php container logs for details.',
    ]);
}

<?php

declare(strict_types=1);

use MeldeVerkehr\Admin\AdminController;
use MeldeVerkehr\Auth\AuthController;
use MeldeVerkehr\Auth\DashboardController;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Install\InstallerController;
use MeldeVerkehr\Install\InstallerService;

/** @var Application $app */

$installer = new InstallerController($app->basePath());
$installerService = new InstallerService($app->basePath());

$app->router()->get('/install', [$installer, 'index']);
$app->router()->post('/install/database', [$installer, 'database']);
$app->router()->get('/install/admin', [$installer, 'adminForm']);
$app->router()->post('/install/admin', [$installer, 'install']);
$app->router()->get('/install/complete', [$installer, 'complete']);

if ($installerService->isInstalled()) {
    $auth = new AuthController($app);
    $dashboard = new DashboardController($app);
    $admin = new AdminController($app);

    $app->router()->get('/register', [$auth, 'registerForm']);
    $app->router()->post('/register', [$auth, 'register']);
    $app->router()->get('/login', [$auth, 'loginForm']);
    $app->router()->post('/login', [$auth, 'login']);
    $app->router()->post('/logout', [$auth, 'logout']);

    $app->router()->get('/verify-email', [$auth, 'verify']);
    $app->router()->get('/verify-email/pending', [$auth, 'verificationPending']);
    $app->router()->post('/verify-email/resend', [$auth, 'resendVerification']);

    $app->router()->get('/forgot-password', [$auth, 'forgotForm']);
    $app->router()->post('/forgot-password', [$auth, 'forgot']);
    $app->router()->get('/reset-password', [$auth, 'resetForm']);
    $app->router()->post('/reset-password', [$auth, 'reset']);

    $app->router()->get('/dashboard', [$dashboard, 'index']);
    $app->router()->get('/admin', [$admin, 'index']);
}

$app->router()->get('/', static function (Request $request) use ($installerService): Response {
    if (!$installerService->isInstalled()) {
        return Response::redirect('/install');
    }

    return Response::redirect('/dashboard');
});

$app->router()->get('/health', static function (Request $request) use ($installerService): Response {
    return Response::json([
        'success' => true,
        'data' => [
            'service' => 'MeldeVerkehr',
            'status' => 'ok',
            'installed' => $installerService->isInstalled(),
            'version' => trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')),
        ],
        'errors' => [],
        'meta' => [],
    ]);
});

<?php

declare(strict_types=1);

namespace MeldeVerkehr\Install;

use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Support\View;
use Throwable;

final class InstallerController
{
    private readonly InstallerService $installer;
    private readonly View $view;

    public function __construct(private readonly string $basePath)
    {
        $this->installer = new InstallerService($basePath);
        $this->view = new View($basePath . '/resources/views');
    }

    public function index(Request $request): Response
    {
        if ($this->installer->isInstalled()) {
            return Response::html($this->view->render('install/locked'), 403);
        }

        $check = (new SystemCheck())->run($this->basePath);

        return Response::html($this->view->render('install/index', [
            'checks' => $check['checks'],
            'systemOk' => $check['ok'],
            'csrf' => Csrf::token(),
            'error' => null,
            'old' => [],
        ]));
    }

    public function database(Request $request): Response
    {
        if ($this->installer->isInstalled()) {
            return Response::html($this->view->render('install/locked'), 403);
        }

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            return Response::html($this->view->render('install/error', [
                'message' => 'Die Installationssitzung ist ungültig oder abgelaufen.',
            ]), 419);
        }

        $database = [
            'host' => trim((string) $request->input('db_host', 'localhost')),
            'port' => (int) $request->input('db_port', 3306),
            'database' => trim((string) $request->input('db_database', '')),
            'username' => trim((string) $request->input('db_username', '')),
            'password' => (string) $request->input('db_password', ''),
            'charset' => 'utf8mb4',
        ];

        $check = (new SystemCheck())->run($this->basePath);
        $result = $this->installer->testDatabase($database);

        if (!$result['ok']) {
            return Response::html($this->view->render('install/index', [
                'checks' => $check['checks'],
                'systemOk' => $check['ok'],
                'csrf' => Csrf::token(),
                'error' => 'Datenbankverbindung fehlgeschlagen: ' . (string) $result['error'],
                'old' => $database,
            ]), 422);
        }

        $_SESSION['installer_database'] = $database;
        $_SESSION['installer_database_version'] = $result['version'];

        return Response::redirect('/install/admin');
    }

    public function adminForm(Request $request): Response
    {
        if ($this->installer->isInstalled()) {
            return Response::html($this->view->render('install/locked'), 403);
        }

        if (!isset($_SESSION['installer_database']) || !is_array($_SESSION['installer_database'])) {
            return Response::redirect('/install');
        }

        return Response::html($this->view->render('install/admin', [
            'csrf' => Csrf::token(),
            'databaseVersion' => (string) ($_SESSION['installer_database_version'] ?? 'unbekannt'),
            'error' => null,
            'old' => [],
        ]));
    }

    public function install(Request $request): Response
    {
        if ($this->installer->isInstalled()) {
            return Response::html($this->view->render('install/locked'), 403);
        }

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            return Response::html($this->view->render('install/error', [
                'message' => 'Die Installationssitzung ist ungültig oder abgelaufen.',
            ]), 419);
        }

        $database = $_SESSION['installer_database'] ?? null;

        if (!is_array($database)) {
            return Response::redirect('/install');
        }

        $app = [
            'name' => trim((string) $request->input('app_name', 'MeldeVerkehr')),
            'url' => rtrim(trim((string) $request->input('app_url', '')), '/'),
            'timezone' => trim((string) $request->input('app_timezone', 'Europe/Berlin')),
        ];

        $admin = [
            'first_name' => trim((string) $request->input('first_name', '')),
            'last_name' => trim((string) $request->input('last_name', '')),
            'email' => trim((string) $request->input('email', '')),
            'password' => (string) $request->input('password', ''),
            'password_confirmation' => (string) $request->input('password_confirmation', ''),
        ];

        $error = $this->validateFinalStep($app, $admin);

        if ($error !== null) {
            return Response::html($this->view->render('install/admin', [
                'csrf' => Csrf::token(),
                'databaseVersion' => (string) ($_SESSION['installer_database_version'] ?? 'unbekannt'),
                'error' => $error,
                'old' => array_merge($app, $admin),
            ]), 422);
        }

        try {
            $result = $this->installer->install($app, $database, $admin);
        } catch (Throwable $e) {
            return Response::html($this->view->render('install/admin', [
                'csrf' => Csrf::token(),
                'databaseVersion' => (string) ($_SESSION['installer_database_version'] ?? 'unbekannt'),
                'error' => 'Installation fehlgeschlagen: ' . $e->getMessage(),
                'old' => array_merge($app, $admin),
            ]), 500);
        }

        unset($_SESSION['installer_database'], $_SESSION['installer_database_version']);
        $_SESSION['installer_completed'] = true;
        $_SESSION['installer_migrations'] = $result['migrations'];
        Csrf::rotate();

        return Response::redirect('/install/complete');
    }

    public function complete(Request $request): Response
    {
        if (!$this->installer->isInstalled()) {
            return Response::redirect('/install');
        }

        $migrations = $_SESSION['installer_migrations'] ?? [];
        unset($_SESSION['installer_migrations'], $_SESSION['installer_completed']);

        return Response::html($this->view->render('install/complete', [
            'migrations' => is_array($migrations) ? $migrations : [],
        ]));
    }

    private function validateFinalStep(array $app, array $admin): ?string
    {
        if ($app['name'] === '') {
            return 'Bitte einen Anwendungsnamen angeben.';
        }

        if (!filter_var($app['url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $app['url'])) {
            return 'Bitte eine gültige HTTP- oder HTTPS-URL angeben.';
        }

        if (!in_array($app['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            return 'Die gewählte Zeitzone ist ungültig.';
        }

        if ($admin['first_name'] === '' || $admin['last_name'] === '') {
            return 'Vor- und Nachname des Superadministrators sind erforderlich.';
        }

        if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
            return 'Bitte eine gültige Administrator-E-Mail-Adresse angeben.';
        }

        if (strlen($admin['password']) < 12) {
            return 'Das Administrator-Passwort muss mindestens 12 Zeichen lang sein.';
        }

        if (!hash_equals($admin['password'], $admin['password_confirmation'])) {
            return 'Die Passwortbestätigung stimmt nicht überein.';
        }

        return null;
    }
}

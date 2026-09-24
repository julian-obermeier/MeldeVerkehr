<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth;

use MeldeVerkehr\Audit\AuditContext;
use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\WebAuthn\WebAuthnService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\View;
use Throwable;

final class PasskeyLoginController
{
    private readonly AuthManager $auth;
    private readonly View $view;

    public function __construct(private readonly Application $app)
    {
        $this->auth = new AuthManager();
        $this->view = new View($app->basePath() . '/resources/views');
    }

    public function page(Request $request): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/dashboard');
        }

        return Response::html($this->view->render('auth/passkey'));
    }

    public function options(Request $request): Response
    {
        $limiter = $this->limiter();
        $key = $limiter->keyFor('passkey', (string) $request->server('REMOTE_ADDR', ''));

        if ($limiter->blocked($key)) {
            return Response::json([
                'success' => false,
                'data' => null,
                'errors' => ['Zu viele fehlgeschlagene Passkey-Anmeldungen.'],
                'meta' => [],
            ], 429);
        }

        return Response::json([
            'success' => true,
            'data' => $this->webauthn()->loginOptions(),
            'errors' => [],
            'meta' => [],
        ]);
    }

    public function login(Request $request): Response
    {
        $limiter = $this->limiter();
        $limitKey = $limiter->keyFor('passkey', (string) $request->server('REMOTE_ADDR', ''));

        if ($limiter->blocked($limitKey)) {
            return Response::json([
                'success' => false,
                'data' => null,
                'errors' => ['Zu viele fehlgeschlagene Passkey-Anmeldungen.'],
                'meta' => [],
            ], 429);
        }

        try {
            $payload = json_decode((string) $request->input('payload', ''), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \DomainException('Ungültige Passkey-Daten.');
            }

            $userId = $this->webauthn()->verifyLogin($payload);
            $user = (new AuthService($this->app->database()))->findById($userId);

            if ($user === null || $user['status'] !== 'ACTIVE' || $user['email_verified_at'] === null) {
                throw new \DomainException('Konto kann nicht per Passkey angemeldet werden.');
            }

            $twoFactor = new TwoFactorService(
                $this->app->database(),
                new SecretCipher((string) $this->app->config()->get('app.key', ''))
            );

            if ($twoFactor->enabled($userId)) {
                $this->auth->beginSecondFactor($userId);
                $redirect = '/two-factor';
                $action = 'PASSKEY_VERIFIED_SECOND_FACTOR_REQUIRED';
            } else {
                $this->auth->login($userId);
                $redirect = '/dashboard';
                $action = 'PASSKEY_LOGIN_SUCCESS';
            }

            (new AuditLogger(
                $this->app->database(),
                (string) $this->app->config()->get('app.key', '')
            ))->log(
                $action,
                'user',
                $userId,
                'USER',
                $userId,
                AuditContext::requestMetadata((string) $this->app->config()->get('app.key', ''))
            );

            $limiter->clear($limitKey);

            return Response::json([
                'success' => true,
                'data' => ['redirect' => $redirect],
                'errors' => [],
                'meta' => [],
            ]);
        } catch (Throwable $e) {
            $limiter->hit($limitKey);

            return Response::json([
                'success' => false,
                'data' => null,
                'errors' => ['Passkey-Anmeldung fehlgeschlagen.'],
                'meta' => [],
            ], 422);
        }
    }

    private function limiter(): LoginRateLimiter
    {
        return new LoginRateLimiter(
            $this->app->database(),
            (string) $this->app->config()->get('app.key', 'meldeverkehr'),
            10,
            15,
            15
        );
    }

    private function webauthn(): WebAuthnService
    {
        return new WebAuthnService(
            $this->app->database(),
            (string) $this->app->config()->get('app.url', ''),
            (string) $this->app->config()->get('app.name', 'MeldeVerkehr')
        );
    }
}

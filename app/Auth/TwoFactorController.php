<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth;

use MeldeVerkehr\Audit\AuditContext;
use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\View;

final class TwoFactorController
{
    private readonly AuthManager $auth;
    private readonly View $view;

    public function __construct(private readonly Application $app)
    {
        $this->auth = new AuthManager();
        $this->view = new View($app->basePath() . '/resources/views');
    }

    public function form(Request $request): Response
    {
        if ($this->auth->pendingSecondFactorId() === null) {
            return Response::redirect('/login');
        }

        return Response::html($this->view->render('auth/two_factor', [
            'csrf' => Csrf::token(),
            'error' => null,
        ]));
    }

    public function verify(Request $request): Response
    {
        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            return Response::redirect('/login');
        }

        $userId = $this->auth->pendingSecondFactorId();

        if ($userId === null) {
            return Response::redirect('/login');
        }

        $service = new TwoFactorService(
            $this->app->database(),
            new SecretCipher((string) $this->app->config()->get('app.key', ''))
        );

        $limiter = new LoginRateLimiter(
            $this->app->database(),
            (string) $this->app->config()->get('app.key', 'meldeverkehr'),
            5,
            15,
            15
        );
        $limitKey = $limiter->keyFor('2fa:' . $userId, (string) $request->server('REMOTE_ADDR', ''));

        if ($limiter->blocked($limitKey)) {
            return Response::html($this->view->render('auth/two_factor', [
                'csrf' => Csrf::token(),
                'error' => 'Zu viele ungültige Codes. Bitte später erneut versuchen.',
            ]), 429);
        }

        if (!$service->verify($userId, trim((string) $request->input('code', '')))) {
            $limiter->hit($limitKey);
            $this->audit()->log(
                'SECOND_FACTOR_FAILED',
                'user',
                $userId,
                'USER',
                $userId,
                AuditContext::requestMetadata((string) $this->app->config()->get('app.key', ''))
            );

            return Response::html($this->view->render('auth/two_factor', [
                'csrf' => Csrf::token(),
                'error' => 'Der Code ist nicht gültig.',
            ]), 422);
        }

        $limiter->clear($limitKey);
        $this->auth->completeSecondFactor();
        Csrf::rotate();

        $this->audit()->log(
            'LOGIN_SUCCESS',
            'user',
            $userId,
            'USER',
            $userId,
            AuditContext::requestMetadata((string) $this->app->config()->get('app.key', ''))
        );

        return Response::redirect('/dashboard');
    }

    private function audit(): AuditLogger
    {
        return new AuditLogger(
            $this->app->database(),
            (string) $this->app->config()->get('app.key', '')
        );
    }
}

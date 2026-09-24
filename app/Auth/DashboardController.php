<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\WebAuthn\WebAuthnService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Operations\OperationsServiceFactory;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\View;

final class DashboardController
{
    private readonly AuthManager $auth;
    private readonly View $view;

    public function __construct(private readonly Application $app)
    {
        $this->auth = new AuthManager();
        $this->view = new View($app->basePath() . '/resources/views');
    }

    public function index(Request $request): Response
    {
        $id = $this->auth->id();

        if ($id === null) {
            return Response::redirect('/login');
        }

        $user = (new AuthService($this->app->database()))->findById($id);

        if ($user === null || $user['status'] !== 'ACTIVE') {
            $this->auth->logout();
            return Response::redirect('/login');
        }

        if ($user['email_verified_at'] === null) {
            return Response::redirect('/verify-email/pending');
        }

        $permissions = new PermissionService($this->app->database());
        $twoFactor = new TwoFactorService(
            $this->app->database(),
            new SecretCipher((string) $this->app->config()->get('app.key', ''))
        );
        $passkeys = (new WebAuthnService(
            $this->app->database(),
            (string) $this->app->config()->get('app.url', ''),
            (string) $this->app->config()->get('app.name', 'MeldeVerkehr')
        ))->listForUser($id);

        $caseService = new CaseService(
            $this->app->database(),
            new AuthorizationService($permissions),
            new SecretCipher((string) $this->app->config()->get('app.key', '')),
            (string) $this->app->config()->get('app.key', ''),
            new AuditLogger(
                $this->app->database(),
                (string) $this->app->config()->get('app.key', '')
            )
        );
        $caseSummary = $caseService->dashboardSummary($id);
        $notifications = OperationsServiceFactory::notifications($this->app);
        $notifications->syncForUser($id);
        $unreadNotifications = $notifications->unreadCount($id);
        $authorityScopes = AuthorityPortalServiceFactory::access($this->app)->scopes($id);

        return Response::html($this->view->render('dashboard/index', [
            'user' => $user,
            'csrf' => Csrf::token(),
            'canAdmin' => $permissions->can($id, 'admin.system'),
            'totpEnabled' => $twoFactor->enabled($id),
            'passkeyCount' => count($passkeys),
            'caseSummary' => $caseSummary,
            'unreadNotifications' => $unreadNotifications,
            'authorityScopes' => $authorityScopes,
        ]));
    }
}

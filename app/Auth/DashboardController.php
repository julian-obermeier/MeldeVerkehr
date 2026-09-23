<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth;

use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\Csrf;
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

        return Response::html($this->view->render('dashboard/index', [
            'user' => $user,
            'csrf' => Csrf::token(),
            'canAdmin' => $permissions->can($id, 'admin.system'),
        ]));
    }
}

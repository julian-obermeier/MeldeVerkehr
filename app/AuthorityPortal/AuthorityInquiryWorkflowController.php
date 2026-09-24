<?php

declare(strict_types=1);

namespace MeldeVerkehr\AuthorityPortal;

use MeldeVerkehr\Auth\AuthManager;
use MeldeVerkehr\Auth\AuthService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Support\View;

final class AuthorityInquiryWorkflowController
{
    private readonly AuthManager $auth;
    private readonly View $view;

    public function __construct(private readonly Application $app)
    {
        $this->auth = new AuthManager();
        $this->view = new View($app->basePath() . '/resources/views');
    }

    public function citizenInbox(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        try {
            $data = AuthorityPortalServiceFactory::inquiries($this->app)->citizenInbox(
                $userId,
                (string) $request->route('id', '')
            );
        } catch (\DomainException $e) {
            return Response::html('<h1>404</h1><p>Vorgang nicht gefunden.</p>', 404);
        }

        return Response::html($this->view->render('authority/inquiries-citizen', [
            'data' => $data,
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('inquiry_message'),
            'error' => $this->pullFlash('inquiry_error'),
        ]));
    }

    public function submitResponse(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = trim((string) $request->input('case_id', ''));

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['inquiry_error'] = 'Sitzung abgelaufen.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/inquiries');
        }

        try {
            $caseId = AuthorityPortalServiceFactory::inquiries($this->app)->submitCitizenResponse(
                $userId,
                (string) $request->route('id', ''),
                (string) $request->input('body', '')
            );
            $_SESSION['inquiry_message'] = 'Antwort wurde an die Behörde zur Prüfung übermittelt.';
        } catch (\InvalidArgumentException|\DomainException $e) {
            $_SESSION['inquiry_error'] = $e->getMessage();
        }

        return Response::redirect('/cases/' . rawurlencode($caseId) . '/inquiries');
    }

    public function authorityReview(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = trim((string) $request->input('case_id', ''));

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['authority_error'] = 'Sitzung abgelaufen.';
            return Response::redirect('/authority/cases/' . rawurlencode($caseId));
        }

        try {
            $caseId = AuthorityPortalServiceFactory::inquiries($this->app)->reviewAuthorityResponse(
                $userId,
                (string) $request->route('id', ''),
                (string) $request->input('decision', ''),
                (string) $request->input('review_note', '')
            );
            $_SESSION['authority_message'] = 'Bürgerantwort wurde geprüft.';
        } catch (\MeldeVerkehr\Auth\AuthorizationException|\InvalidArgumentException|\DomainException $e) {
            $_SESSION['authority_error'] = $e->getMessage();
        }

        return Response::redirect('/authority/cases/' . rawurlencode($caseId));
    }

    private function requireUser(): string|Response
    {
        $userId = $this->auth->userId();

        if ($userId === null) {
            return Response::redirect('/login');
        }

        $user = (new AuthService($this->app->database()))->findById($userId);
        if ($user === null || $user['status'] !== 'ACTIVE') {
            $this->auth->logout();
            return Response::redirect('/login');
        }

        if ($user['email_verified_at'] === null) {
            return Response::redirect('/verify-email/pending');
        }

        return $userId;
    }

    private function pullFlash(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return is_string($value) ? $value : null;
    }
}

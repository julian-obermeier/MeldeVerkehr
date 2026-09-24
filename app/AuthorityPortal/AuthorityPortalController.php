<?php

declare(strict_types=1);

namespace MeldeVerkehr\AuthorityPortal;

use MeldeVerkehr\Auth\AuthManager;
use MeldeVerkehr\Auth\AuthService;
use MeldeVerkehr\Auth\AuthorizationException;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Support\View;

final class AuthorityPortalController
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
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $access = AuthorityPortalServiceFactory::access($this->app);
        $scopes = $access->scopes($userId);

        if ($scopes === []) {
            return Response::html('<h1>403</h1><p>Kein Behördenzugriff zugeordnet.</p>', 403);
        }

        $authorityId = trim((string) $request->query('authority', ''));
        if ($authorityId === '') {
            $authorityId = (string) $scopes[0]['authority_id'];
        }

        try {
            $access->assertAuthority($userId, $authorityId, 'authority.case.view');
            $cases = AuthorityPortalServiceFactory::portal($this->app)->inbox(
                $userId,
                $authorityId,
                $this->nullable($request->query('status')),
                $this->nullable($request->query('q'))
            );
        } catch (AuthorizationException $e) {
            return Response::html('<h1>403</h1><p>Kein Zugriff auf diese Behörde.</p>', 403);
        }

        return Response::html($this->view->render('authority/index', [
            'scopes' => $scopes,
            'authorityId' => $authorityId,
            'cases' => $cases,
            'query' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('authority_message'),
            'error' => $this->pullFlash('authority_error'),
        ]));
    }

    public function case(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        try {
            $detail = AuthorityPortalServiceFactory::portal($this->app)->caseDetail(
                $userId,
                (string) $request->route('id', '')
            );
            $scope = AuthorityPortalServiceFactory::access($this->app)->assertAuthority(
                $userId,
                (string) $detail['authority']['id'],
                'authority.case.view'
            );

            foreach ($detail['inquiries'] as &$inquiry) {
                $inquiry['responses'] = AuthorityPortalServiceFactory::inquiries($this->app)
                    ->authorityResponses($userId, (string) $inquiry['id']);
            }
            unset($inquiry);
        } catch (AuthorizationException|\DomainException $e) {
            return Response::html('<h1>404</h1><p>Behördenvorgang nicht gefunden.</p>', 404);
        }

        return Response::html($this->view->render('authority/case', [
            'detail' => $detail,
            'scope' => $scope,
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('authority_message'),
            'error' => $this->pullFlash('authority_error'),
        ]));
    }

    public function createInquiry(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = (string) $request->route('id', '');
        if (!$this->csrf($request)) {
            return Response::redirect('/authority/cases/' . rawurlencode($caseId));
        }

        try {
            AuthorityPortalServiceFactory::portal($this->app)->createInquiry(
                $userId,
                $caseId,
                (string) $request->input('inquiry_type', ''),
                (string) $request->input('subject', ''),
                (string) $request->input('body', ''),
                $this->dateTime($request->input('due_at'))
            );
            $_SESSION['authority_message'] = 'Strukturierte Behördenanfrage wurde erstellt.';
        } catch (AuthorizationException|\InvalidArgumentException|\DomainException $e) {
            $_SESSION['authority_error'] = $e->getMessage();
        }

        return Response::redirect('/authority/cases/' . rawurlencode($caseId));
    }

    public function holder(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = (string) $request->route('id', '');

        try {
            $detail = AuthorityPortalServiceFactory::portal($this->app)->caseDetail($userId, $caseId);
            $record = AuthorityPortalServiceFactory::holders($this->app)->get($userId, $caseId);
        } catch (AuthorizationException $e) {
            return Response::html('<h1>403</h1><p>Kein Zugriff auf Halterdaten.</p>', 403);
        } catch (\DomainException $e) {
            return Response::html('<h1>404</h1><p>Behördenvorgang nicht gefunden.</p>', 404);
        }

        return Response::html($this->view->render('authority/holder', [
            'detail' => $detail,
            'record' => $record,
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('authority_message'),
            'error' => $this->pullFlash('authority_error'),
        ]));
    }

    public function saveHolder(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = (string) $request->route('id', '');
        if (!$this->csrf($request)) {
            return Response::redirect('/authority/cases/' . rawurlencode($caseId) . '/holder');
        }

        try {
            AuthorityPortalServiceFactory::holders($this->app)->save(
                $userId,
                $caseId,
                (string) $request->input('holder_name', ''),
                (string) $request->input('holder_address', ''),
                $this->nullable($request->input('date_of_birth')),
                []
            );
            $_SESSION['authority_message'] = 'Halterdatensatz verschlüsselt gespeichert.';
        } catch (AuthorizationException|\InvalidArgumentException|\DomainException $e) {
            $_SESSION['authority_error'] = $e->getMessage();
        }

        return Response::redirect('/authority/cases/' . rawurlencode($caseId) . '/holder');
    }

    public function admin(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $authorityId = trim((string) $request->query('authority', ''));
        $access = AuthorityPortalServiceFactory::access($this->app);

        if ($authorityId === '') {
            foreach ($access->scopes($userId) as $scope) {
                if ((string) $scope['scope_role'] === 'AUTHORITY_ADMIN') {
                    $authorityId = (string) $scope['authority_id'];
                    break;
                }
            }
        }

        if ($authorityId === '') {
            return Response::html('<h1>403</h1><p>Keine Authority-Administration zugeordnet.</p>', 403);
        }

        try {
            $scope = $access->assertAuthorityAdmin($userId, $authorityId);
            $users = $access->users($userId, $authorityId);
            $tokens = AuthorityPortalServiceFactory::tokens($this->app)->list($userId, $authorityId);
        } catch (AuthorizationException $e) {
            return Response::html('<h1>403</h1><p>Kein Administrationszugriff.</p>', 403);
        }

        $rawToken = $_SESSION['authority_token_once'] ?? null;
        unset($_SESSION['authority_token_once']);

        return Response::html($this->view->render('authority/admin', [
            'scope' => $scope,
            'users' => $users,
            'tokens' => $tokens,
            'rawToken' => is_string($rawToken) ? $rawToken : null,
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('authority_message'),
            'error' => $this->pullFlash('authority_error'),
        ]));
    }

    public function assignUser(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $authorityId = (string) $request->route('id', '');
        if (!$this->csrf($request)) {
            return Response::redirect('/authority/admin?authority=' . rawurlencode($authorityId));
        }

        try {
            $email = mb_strtolower(trim((string) $request->input('email', '')), 'UTF-8');
            $stmt = $this->app->database()->prepare(
                'SELECT id FROM users WHERE LOWER(email) = :email AND status = "ACTIVE" LIMIT 1'
            );
            $stmt->execute(['email' => $email]);
            $target = $stmt->fetchColumn();

            if (!is_string($target) || $target === '') {
                throw new \DomainException('Aktiver Benutzer mit dieser E-Mail wurde nicht gefunden.');
            }

            AuthorityPortalServiceFactory::access($this->app)->assignScope(
                $userId,
                $target,
                $authorityId,
                (string) $request->input('scope_role', 'AUTHORITY_USER')
            );
            $_SESSION['authority_message'] = 'Behördenzugriff wurde zugeordnet.';
        } catch (AuthorizationException|\InvalidArgumentException|\DomainException $e) {
            $_SESSION['authority_error'] = $e->getMessage();
        }

        return Response::redirect('/authority/admin?authority=' . rawurlencode($authorityId));
    }

    public function createToken(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $authorityId = (string) $request->route('id', '');
        if (!$this->csrf($request)) {
            return Response::redirect('/authority/admin?authority=' . rawurlencode($authorityId));
        }

        $scopes = $request->input('scopes', []);
        if (!is_array($scopes)) {
            $scopes = [];
        }

        try {
            $token = AuthorityPortalServiceFactory::tokens($this->app)->create(
                $userId,
                $authorityId,
                (string) $request->input('name', ''),
                $scopes,
                $this->expiryDate($request->input('expires_at'))
            );
            $_SESSION['authority_token_once'] = $token['token'];
            $_SESSION['authority_message'] = 'API-Token erstellt. Der Klartext wird nur einmal angezeigt.';
        } catch (AuthorizationException|\InvalidArgumentException $e) {
            $_SESSION['authority_error'] = $e->getMessage();
        }

        return Response::redirect('/authority/admin?authority=' . rawurlencode($authorityId));
    }

    public function revokeToken(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $authorityId = (string) $request->input('authority_id', '');
        if (!$this->csrf($request)) {
            return Response::redirect('/authority/admin?authority=' . rawurlencode($authorityId));
        }

        try {
            AuthorityPortalServiceFactory::tokens($this->app)->revoke(
                $userId,
                $authorityId,
                (string) $request->route('id', '')
            );
            $_SESSION['authority_message'] = 'API-Token widerrufen.';
        } catch (AuthorizationException|\DomainException $e) {
            $_SESSION['authority_error'] = $e->getMessage();
        }

        return Response::redirect('/authority/admin?authority=' . rawurlencode($authorityId));
    }

    public function export(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        try {
            $authorityId = (string) $request->route('id', '');
            $format = (string) $request->route('format', 'csv');
            $result = AuthorityPortalServiceFactory::exports($this->app)->export(
                $userId,
                $authorityId,
                $format,
                [
                    'status' => $this->nullable($request->query('status')),
                    'q' => $this->nullable($request->query('q')),
                ]
            );

            return Response::binary(
                $result['body'],
                $result['mime_type'],
                200,
                [
                    'Content-Disposition' => 'attachment; filename="authority-cases.' . $result['extension'] . '"',
                    'Cache-Control' => 'private, no-store, max-age=0',
                ]
            );
        } catch (AuthorizationException $e) {
            return Response::html('<h1>403</h1>', 403);
        }
    }

    private function requireUser(): string|Response
    {
        $userId = $this->auth->id();

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

    private function csrf(Request $request): bool
    {
        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['authority_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return false;
        }

        return true;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function dateTime(mixed $value): ?\DateTimeImmutable
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        try {
            $local = new \DateTimeImmutable($value, new \DateTimeZone('Europe/Berlin'));
            return $local->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Fristdatum ist ungültig.');
        }
    }

    private function expiryDate(mixed $value): ?\DateTimeImmutable
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value . ' 23:59:59',
            new \DateTimeZone('Europe/Berlin')
        );

        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Ablaufdatum ist ungültig.');
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }

    private function pullFlash(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return is_string($value) ? $value : null;
    }
}

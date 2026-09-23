<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth;

use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Mail\PhpMailTransport;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Support\View;
use Throwable;

final class AuthController
{
    private readonly AuthManager $auth;
    private readonly View $view;

    public function __construct(private readonly Application $app)
    {
        $this->auth = new AuthManager();
        $this->view = new View($app->basePath() . '/resources/views');
    }

    public function registerForm(Request $request): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/dashboard');
        }

        return Response::html($this->view->render('auth/register', [
            'csrf' => Csrf::token(),
            'error' => null,
            'old' => [],
        ]));
    }

    public function register(Request $request): Response
    {
        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            return $this->csrfError();
        }

        $data = [
            'first_name' => trim((string) $request->input('first_name', '')),
            'last_name' => trim((string) $request->input('last_name', '')),
            'email' => trim((string) $request->input('email', '')),
            'password' => (string) $request->input('password', ''),
        ];

        if (!hash_equals($data['password'], (string) $request->input('password_confirmation', ''))) {
            return $this->registerError('Die Passwortbestätigung stimmt nicht überein.', $data);
        }

        try {
            $user = (new AuthService($this->app->database()))->register($data);
            $this->audit()->log(
                'USER_REGISTERED',
                'user',
                (string) $user['id'],
                'USER',
                (string) $user['id'],
                $this->auditMetadata()
            );
            $this->auth->login((string) $user['id']);
            Csrf::rotate();

            $sent = $this->verificationService()->send((string) $user['id']);
            $_SESSION['flash_auth'] = $sent
                ? 'Konto erstellt. Bitte bestätige jetzt deine E-Mail-Adresse.'
                : 'Konto erstellt. Die Verifikationsmail konnte aktuell nicht versendet werden. Du kannst den Versand erneut anstoßen.';

            return Response::redirect('/verify-email/pending');
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->registerError($e->getMessage(), $data);
        } catch (Throwable $e) {
            throw $e;
        }
    }

    public function loginForm(Request $request): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/dashboard');
        }

        return Response::html($this->view->render('auth/login', [
            'csrf' => Csrf::token(),
            'error' => null,
            'message' => $this->pullFlash(),
            'email' => '',
        ]));
    }

    public function login(Request $request): Response
    {
        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            return $this->csrfError();
        }

        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $ip = (string) $request->server('REMOTE_ADDR', '');

        $limiter = new LoginRateLimiter(
            $this->app->database(),
            (string) $this->app->config()->get('app.key', 'meldeverkehr')
        );
        $key = $limiter->keyFor($email, $ip);

        if ($limiter->blocked($key)) {
            $this->audit()->log(
                'LOGIN_BLOCKED',
                'user',
                null,
                'ANONYMOUS',
                null,
                $this->auditMetadata(['email_hash' => $this->emailHash($email)])
            );
            return $this->loginError('Zu viele fehlgeschlagene Anmeldeversuche. Bitte später erneut versuchen.', $email, 429);
        }

        $user = (new AuthService($this->app->database()))->authenticate($email, $password);

        if ($user === null) {
            $limiter->hit($key);
            $this->audit()->log(
                'LOGIN_FAILED',
                'user',
                null,
                'ANONYMOUS',
                null,
                $this->auditMetadata(['email_hash' => $this->emailHash($email)])
            );
            return $this->loginError('E-Mail-Adresse oder Passwort ist nicht korrekt.', $email, 422);
        }

        $limiter->clear($key);
        $this->auth->login((string) $user['id']);
        Csrf::rotate();
        $this->audit()->log(
            'LOGIN_SUCCESS',
            'user',
            (string) $user['id'],
            'USER',
            (string) $user['id'],
            $this->auditMetadata()
        );

        if ($user['email_verified_at'] === null) {
            return Response::redirect('/verify-email/pending');
        }

        return Response::redirect('/dashboard');
    }

    public function logout(Request $request): Response
    {
        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            return $this->csrfError();
        }

        $userId = $this->auth->id();

        if ($userId !== null) {
            $this->audit()->log(
                'LOGOUT',
                'user',
                $userId,
                'USER',
                $userId,
                $this->auditMetadata()
            );
        }

        $this->auth->logout();
        Csrf::rotate();

        return Response::redirect('/login');
    }

    public function verificationPending(Request $request): Response
    {
        $user = $this->currentUser();

        if ($user === null) {
            return Response::redirect('/login');
        }

        if ($user['email_verified_at'] !== null) {
            return Response::redirect('/dashboard');
        }

        return Response::html($this->view->render('auth/verify_pending', [
            'csrf' => Csrf::token(),
            'email' => (string) $user['email'],
            'message' => $this->pullFlash(),
        ]));
    }

    public function resendVerification(Request $request): Response
    {
        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            return $this->csrfError();
        }

        $user = $this->currentUser();

        if ($user === null) {
            return Response::redirect('/login');
        }

        $sent = $this->verificationService()->send((string) $user['id']);
        $_SESSION['flash_auth'] = $sent
            ? 'Die Bestätigungsmail wurde erneut versendet.'
            : 'Die Bestätigungsmail konnte nicht versendet werden. Bitte prüfe die Mailkonfiguration.';

        return Response::redirect('/verify-email/pending');
    }

    public function verify(Request $request): Response
    {
        $token = (string) $request->query('token', '');
        $userId = $this->verificationService()->verify($token);

        if ($userId === null) {
            return Response::html($this->view->render('auth/result', [
                'title' => 'Bestätigung nicht möglich',
                'message' => 'Der Bestätigungslink ist ungültig oder abgelaufen.',
                'link' => '/login',
                'linkText' => 'Zum Login',
            ]), 422);
        }

        if (!$this->auth->check()) {
            $this->auth->login($userId);
        }

        $this->audit()->log(
            'EMAIL_VERIFIED',
            'user',
            $userId,
            'USER',
            $userId,
            $this->auditMetadata()
        );

        return Response::html($this->view->render('auth/result', [
            'title' => 'E-Mail bestätigt',
            'message' => 'Deine E-Mail-Adresse wurde erfolgreich bestätigt.',
            'link' => '/dashboard',
            'linkText' => 'Zum Dashboard',
        ]));
    }

    public function forgotForm(Request $request): Response
    {
        return Response::html($this->view->render('auth/forgot', [
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash(),
        ]));
    }

    public function forgot(Request $request): Response
    {
        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            return $this->csrfError();
        }

        (new PasswordResetService(
            $this->app->database(),
            $this->mailTransport(),
            (string) $this->app->config()->get('app.url', ''),
            (string) $this->app->config()->get('app.name', 'MeldeVerkehr')
        ))->request((string) $request->input('email', ''));

        $_SESSION['flash_auth'] = 'Falls ein aktives Konto mit dieser E-Mail-Adresse existiert, wurde ein Link zum Zurücksetzen versendet.';

        return Response::redirect('/forgot-password');
    }

    public function resetForm(Request $request): Response
    {
        return Response::html($this->view->render('auth/reset', [
            'csrf' => Csrf::token(),
            'token' => (string) $request->query('token', ''),
            'error' => null,
        ]));
    }

    public function reset(Request $request): Response
    {
        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            return $this->csrfError();
        }

        $password = (string) $request->input('password', '');
        $confirmation = (string) $request->input('password_confirmation', '');
        $token = (string) $request->input('token', '');

        if (strlen($password) < 12) {
            return $this->resetError($token, 'Das neue Passwort muss mindestens 12 Zeichen lang sein.');
        }

        if (!hash_equals($password, $confirmation)) {
            return $this->resetError($token, 'Die Passwortbestätigung stimmt nicht überein.');
        }

        $service = new PasswordResetService(
            $this->app->database(),
            $this->mailTransport(),
            (string) $this->app->config()->get('app.url', ''),
            (string) $this->app->config()->get('app.name', 'MeldeVerkehr')
        );

        if (!$service->reset($token, $password)) {
            return $this->resetError($token, 'Der Link ist ungültig oder abgelaufen.');
        }

        $this->audit()->log(
            'PASSWORD_RESET',
            'user',
            null,
            'ANONYMOUS',
            null,
            $this->auditMetadata(['token_hash' => hash('sha256', $token)])
        );

        $_SESSION['flash_auth'] = 'Das Passwort wurde geändert. Du kannst dich jetzt anmelden.';

        return Response::redirect('/login');
    }

    private function currentUser(): ?array
    {
        $id = $this->auth->id();

        return $id === null ? null : (new AuthService($this->app->database()))->findById($id);
    }

    private function verificationService(): EmailVerificationService
    {
        return new EmailVerificationService(
            $this->app->database(),
            $this->mailTransport(),
            (string) $this->app->config()->get('app.url', ''),
            (string) $this->app->config()->get('app.name', 'MeldeVerkehr')
        );
    }

    private function mailTransport(): PhpMailTransport
    {
        return new PhpMailTransport(
            (string) $this->app->config()->get('mail.from_address', ''),
            (string) $this->app->config()->get('mail.from_name', 'MeldeVerkehr')
        );
    }

    private function registerError(string $message, array $old): Response
    {
        unset($old['password']);

        return Response::html($this->view->render('auth/register', [
            'csrf' => Csrf::token(),
            'error' => $message,
            'old' => $old,
        ]), 422);
    }

    private function loginError(string $message, string $email, int $status): Response
    {
        return Response::html($this->view->render('auth/login', [
            'csrf' => Csrf::token(),
            'error' => $message,
            'message' => null,
            'email' => $email,
        ]), $status);
    }

    private function resetError(string $token, string $message): Response
    {
        return Response::html($this->view->render('auth/reset', [
            'csrf' => Csrf::token(),
            'token' => $token,
            'error' => $message,
        ]), 422);
    }

    private function csrfError(): Response
    {
        return Response::html($this->view->render('auth/result', [
            'title' => 'Sitzung abgelaufen',
            'message' => 'Bitte lade die Seite neu und versuche es erneut.',
            'link' => '/login',
            'linkText' => 'Zum Login',
        ]), 419);
    }

    private function audit(): AuditLogger
    {
        return new AuditLogger(
            $this->app->database(),
            (string) $this->app->config()->get('app.key', 'meldeverkehr')
        );
    }

    private function auditMetadata(array $extra = []): array
    {
        return array_merge(
            AuditContext::requestMetadata((string) $this->app->config()->get('app.key', 'meldeverkehr')),
            $extra
        );
    }

    private function emailHash(string $email): string
    {
        return hash_hmac(
            'sha256',
            strtolower(trim($email)),
            (string) $this->app->config()->get('app.key', 'meldeverkehr')
        );
    }

    private function pullFlash(): ?string
    {
        $message = $_SESSION['flash_auth'] ?? null;
        unset($_SESSION['flash_auth']);

        return is_string($message) ? $message : null;
    }
}

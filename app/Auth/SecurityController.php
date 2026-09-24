<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth;

use MeldeVerkehr\Audit\AuditContext;
use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\WebAuthn\WebAuthnService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\View;
use Throwable;

final class SecurityController
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
        $user = $this->currentUser();

        if ($user === null) {
            return Response::redirect('/login');
        }

        return Response::html($this->view->render('settings/security', [
            'user' => $user,
            'csrf' => Csrf::token(),
            'totpEnabled' => $this->totp()->enabled((string) $user['id']),
            'passkeys' => $this->webauthn()->listForUser((string) $user['id']),
            'setup' => $this->pullSessionArray('totp_setup_display'),
            'recoveryCodes' => $this->pullSessionArray('recovery_codes_display'),
            'message' => $this->pullSessionString('security_message'),
            'error' => $this->pullSessionString('security_error'),
        ]));
    }

    public function startTotp(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect('/settings/security');
        }

        $user = $this->currentUser();

        if ($user === null || !$this->reauthenticate($user, (string) $request->input('password', ''))) {
            $_SESSION['security_error'] = 'Aktuelles Passwort ist nicht korrekt.';
            return Response::redirect('/settings/security');
        }

        $setup = $this->totp()->beginSetup(
            (string) $user['id'],
            (string) $user['email'],
            (string) $this->app->config()->get('app.name', 'MeldeVerkehr')
        );

        $_SESSION['totp_setup_display'] = $setup;
        $_SESSION['security_message'] = 'TOTP wurde vorbereitet. Bestätige die Einrichtung mit einem aktuellen 6-stelligen Code.';

        return Response::redirect('/settings/security');
    }

    public function confirmTotp(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect('/settings/security');
        }

        $user = $this->currentUser();

        if ($user === null) {
            return Response::redirect('/login');
        }

        $codes = $this->totp()->confirm((string) $user['id'], trim((string) $request->input('code', '')));

        if ($codes === null) {
            $_SESSION['security_error'] = 'Der TOTP-Code konnte nicht bestätigt werden.';
            return Response::redirect('/settings/security');
        }

        $_SESSION['recovery_codes_display'] = $codes;
        $_SESSION['security_message'] = 'Zwei-Faktor-Authentifizierung ist jetzt aktiv. Speichere die Recovery-Codes sicher.';

        $this->audit('TOTP_ENABLED', (string) $user['id']);

        return Response::redirect('/settings/security');
    }

    public function disableTotp(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect('/settings/security');
        }

        $user = $this->currentUser();

        if ($user === null || !$this->reauthenticate($user, (string) $request->input('password', ''))) {
            $_SESSION['security_error'] = 'Aktuelles Passwort ist nicht korrekt.';
            return Response::redirect('/settings/security');
        }

        $this->totp()->disable((string) $user['id']);
        $_SESSION['security_message'] = 'Zwei-Faktor-Authentifizierung wurde deaktiviert.';
        $this->audit('TOTP_DISABLED', (string) $user['id']);

        return Response::redirect('/settings/security');
    }

    public function passkeyOptions(Request $request): Response
    {
        $user = $this->currentUser();

        if ($user === null || $user['email_verified_at'] === null) {
            return Response::json(['success' => false, 'errors' => ['Nicht angemeldet.']], 401);
        }

        return Response::json([
            'success' => true,
            'data' => $this->webauthn()->registrationOptions($user),
            'errors' => [],
            'meta' => [],
        ]);
    }

    public function registerPasskey(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::json(['success' => false, 'errors' => ['Sitzung abgelaufen.']], 419);
        }

        $user = $this->currentUser();

        if ($user === null) {
            return Response::json(['success' => false, 'errors' => ['Nicht angemeldet.']], 401);
        }

        if (!$this->reauthenticate($user, (string) $request->input('password', ''))) {
            return Response::json(['success' => false, 'errors' => ['Aktuelles Passwort ist nicht korrekt.']], 422);
        }

        try {
            $payload = json_decode((string) $request->input('payload', ''), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \DomainException('Ungültige Passkey-Daten.');
            }

            $payload['label'] = trim((string) $request->input('label', 'Passkey'));
            $credentialId = $this->webauthn()->register((string) $user['id'], $payload);
            $this->audit('PASSKEY_REGISTERED', (string) $user['id'], ['credential_hash' => hash('sha256', $credentialId)]);

            return Response::json([
                'success' => true,
                'data' => ['redirect' => '/settings/security'],
                'errors' => [],
                'meta' => [],
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'success' => false,
                'data' => null,
                'errors' => [$e->getMessage()],
                'meta' => [],
            ], 422);
        }
    }

    public function deletePasskey(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect('/settings/security');
        }

        $user = $this->currentUser();

        if ($user === null || !$this->reauthenticate($user, (string) $request->input('password', ''))) {
            $_SESSION['security_error'] = 'Aktuelles Passwort ist nicht korrekt.';
            return Response::redirect('/settings/security');
        }

        $id = trim((string) $request->input('passkey_id', ''));

        if ($id === '' || !$this->webauthn()->delete((string) $user['id'], $id)) {
            $_SESSION['security_error'] = 'Passkey konnte nicht entfernt werden.';
            return Response::redirect('/settings/security');
        }

        $_SESSION['security_message'] = 'Passkey wurde entfernt.';
        $this->audit('PASSKEY_DELETED', (string) $user['id'], ['passkey_id_hash' => hash('sha256', $id)]);

        return Response::redirect('/settings/security');
    }

    private function currentUser(): ?array
    {
        $id = $this->auth->id();

        return $id === null ? null : (new AuthService($this->app->database()))->findById($id);
    }

    private function reauthenticate(array $user, string $password): bool
    {
        return (new AuthService($this->app->database()))
            ->authenticate((string) $user['email'], $password) !== null;
    }

    private function totp(): TwoFactorService
    {
        return new TwoFactorService(
            $this->app->database(),
            new SecretCipher((string) $this->app->config()->get('app.key', ''))
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

    private function validCsrf(Request $request): bool
    {
        return Csrf::validate((string) $request->input('_csrf', ''));
    }

    private function audit(string $action, string $userId, array $extra = []): void
    {
        (new AuditLogger(
            $this->app->database(),
            (string) $this->app->config()->get('app.key', '')
        ))->log(
            $action,
            'user',
            $userId,
            'USER',
            $userId,
            array_merge(
                AuditContext::requestMetadata((string) $this->app->config()->get('app.key', '')),
                $extra
            )
        );
    }

    private function pullSessionString(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return is_string($value) ? $value : null;
    }

    private function pullSessionArray(string $key): ?array
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return is_array($value) ? $value : null;
    }
}

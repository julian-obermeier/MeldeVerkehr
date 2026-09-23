<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth;

use MeldeVerkehr\Mail\MailTransportInterface;
use MeldeVerkehr\Support\Uuid;
use PDO;

final class PasswordResetService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MailTransportInterface $mailer,
        private readonly string $appUrl,
        private readonly string $appName
    ) {
    }

    public function request(string $email): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email FROM users WHERE email = :email AND status = "ACTIVE" LIMIT 1'
        );
        $stmt->execute(['email' => strtolower(trim($email))]);
        $user = $stmt->fetch();

        if (!is_array($user)) {
            return;
        }

        $raw = bin2hex(random_bytes(32));

        $this->pdo->prepare(
            'UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id AND used_at IS NULL'
        )->execute(['user_id' => $user['id']]);

        $insert = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens
             (id, user_id, token_hash, expires_at, used_at, created_at)
             VALUES (:id, :user_id, :hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE), NULL, UTC_TIMESTAMP())'
        );
        $insert->execute([
            'id' => Uuid::v4(),
            'user_id' => $user['id'],
            'hash' => hash('sha256', $raw),
        ]);

        $url = rtrim($this->appUrl, '/') . '/reset-password?token=' . urlencode($raw);
        $subject = $this->appName . ' – Passwort zurücksetzen';
        $html = '<p>Für dein Konto wurde ein Zurücksetzen des Passworts angefordert.</p>'
            . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Passwort zurücksetzen</a></p>'
            . '<p>Der Link ist 30 Minuten gültig. Falls du die Anfrage nicht gestellt hast, ignoriere diese Nachricht.</p>';

        $this->mailer->send((string) $user['email'], $subject, $html, strip_tags($html) . "\n" . $url);
    }

    public function reset(string $rawToken, string $password): bool
    {
        if ($rawToken === '' || strlen($password) < 12) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, user_id FROM password_reset_tokens
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $rawToken)]);
        $token = $stmt->fetch();

        if (!is_array($token)) {
            return false;
        }

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare(
                'UPDATE users SET password_hash = :hash, updated_at = UTC_TIMESTAMP() WHERE id = :id'
            )->execute([
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $token['user_id'],
            ]);

            $this->pdo->prepare(
                'UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE id = :id'
            )->execute(['id' => $token['id']]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return true;
    }
}

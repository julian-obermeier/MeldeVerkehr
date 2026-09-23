<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth;

use MeldeVerkehr\Mail\MailTransportInterface;
use MeldeVerkehr\Support\Uuid;
use PDO;

final class EmailVerificationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MailTransportInterface $mailer,
        private readonly string $appUrl,
        private readonly string $appName
    ) {
    }

    public function send(string $userId): bool
    {
        $user = $this->findUser($userId);

        if ($user === null || $user['email_verified_at'] !== null) {
            return false;
        }

        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);

        $this->pdo->prepare(
            'UPDATE email_verification_tokens SET used_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id AND used_at IS NULL'
        )->execute(['user_id' => $userId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO email_verification_tokens
             (id, user_id, token_hash, expires_at, used_at, created_at)
             VALUES (:id, :user_id, :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR), NULL, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'token_hash' => $hash,
        ]);

        $url = rtrim($this->appUrl, '/') . '/verify-email?token=' . urlencode($raw);
        $subject = $this->appName . ' – E-Mail-Adresse bestätigen';
        $html = '<p>Bitte bestätige deine E-Mail-Adresse für ' . htmlspecialchars($this->appName, ENT_QUOTES, 'UTF-8') . '.</p>'
            . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">E-Mail-Adresse bestätigen</a></p>'
            . '<p>Der Link ist 24 Stunden gültig.</p>';

        return $this->mailer->send((string) $user['email'], $subject, $html, strip_tags($html) . "\n" . $url);
    }

    public function verify(string $rawToken): ?string
    {
        if ($rawToken === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, user_id
             FROM email_verification_tokens
             WHERE token_hash = :hash
               AND used_at IS NULL
               AND expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $rawToken)]);
        $token = $stmt->fetch();

        if (!is_array($token)) {
            return null;
        }

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare(
                'UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            )->execute(['id' => $token['user_id']]);

            $this->pdo->prepare(
                'UPDATE email_verification_tokens SET used_at = UTC_TIMESTAMP() WHERE id = :id'
            )->execute(['id' => $token['id']]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return (string) $token['user_id'];
    }

    private function findUser(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, email_verified_at FROM users WHERE id = :id AND status = "ACTIVE" LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return is_array($user) ? $user : null;
    }
}

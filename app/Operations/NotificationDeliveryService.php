<?php

declare(strict_types=1);

namespace MeldeVerkehr\Operations;

use MeldeVerkehr\Mail\MailTransportInterface;
use MeldeVerkehr\Support\Uuid;
use PDO;

final class NotificationDeliveryService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?MailTransportInterface $mail,
        private readonly ?WebPushService $push,
        private readonly string $appUrl
    ) {
    }

    public function dispatch(
        string $userId,
        string $eventKey,
        ?string $uniqueKey,
        string $priority,
        string $title,
        ?string $body,
        ?string $actionUrl,
        bool $emailEnabled,
        bool $pushEnabled
    ): int {
        $delivered = 0;
        $key = $uniqueKey !== null && trim($uniqueKey) !== ''
            ? mb_substr(trim($uniqueKey), 0, 190)
            : 'adhoc:' . Uuid::v4();

        if ($emailEnabled && $this->mail !== null) {
            $delivered += $this->deliverEmail(
                $userId,
                $eventKey,
                $key,
                $priority,
                $title,
                $body,
                $actionUrl
            ) ? 1 : 0;
        }

        if ($pushEnabled && $this->push !== null && $this->push->configured() && $this->push->activeCount($userId) > 0) {
            $delivered += $this->deliverPush($userId, $eventKey, $key) ? 1 : 0;
        }

        return $delivered;
    }

    private function deliverEmail(
        string $userId,
        string $eventKey,
        string $uniqueKey,
        string $priority,
        string $title,
        ?string $body,
        ?string $actionUrl
    ): bool {
        $row = $this->reserve($userId, $eventKey, $uniqueKey, 'EMAIL');
        if ($row === null) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT email FROM users
             WHERE id = :id AND status = "ACTIVE" AND email_verified_at IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $email = $stmt->fetchColumn();

        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->finish((string) $row['id'], false, 'Keine bestätigte E-Mail-Adresse verfügbar.');
            return false;
        }

        $url = $this->absoluteUrl($actionUrl);
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeBody = htmlspecialchars((string) ($body ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = $url === null ? null : htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<h1 style="font-size:20px">' . $safeTitle . '</h1>'
            . ($safeBody !== '' ? '<p>' . nl2br($safeBody) . '</p>' : '')
            . '<p><strong>Priorität:</strong> ' . htmlspecialchars($priority, ENT_QUOTES, 'UTF-8') . '</p>'
            . ($safeUrl !== null ? '<p><a href="' . $safeUrl . '">In MeldeVerkehr öffnen</a></p>' : '');
        $text = $title
            . ($body !== null && trim($body) !== '' ? "\n\n" . trim($body) : '')
            . "\n\nPriorität: " . $priority
            . ($url !== null ? "\n" . $url : '');

        $ok = $this->mail->send($email, '[MeldeVerkehr] ' . $title, $html, $text);
        $this->finish((string) $row['id'], $ok, $ok ? null : 'Mail-Transport meldete einen Versandfehler.');

        return $ok;
    }

    private function deliverPush(
        string $userId,
        string $eventKey,
        string $uniqueKey
    ): bool {
        $row = $this->reserve($userId, $eventKey, $uniqueKey, 'PUSH');
        if ($row === null) {
            return false;
        }

        $ok = $this->push?->sendSignal($userId) ?? false;
        $this->finish((string) $row['id'], $ok, $ok ? null : 'Kein aktiver Push-Endpunkt konnte erreicht werden.');

        return $ok;
    }

    private function reserve(
        string $userId,
        string $eventKey,
        string $uniqueKey,
        string $channel
    ): ?array {
        $this->pdo->prepare(
            'INSERT IGNORE INTO notification_channel_deliveries
             (id, user_id, event_key, unique_key, channel, status, attempts, created_at, updated_at)
             VALUES
             (:id, :user_id, :event_key, :unique_key, :channel, "PENDING", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'event_key' => mb_substr($eventKey, 0, 100),
            'unique_key' => $uniqueKey,
            'channel' => $channel,
        ]);

        $stmt = $this->pdo->prepare(
            'SELECT id, status, attempts
             FROM notification_channel_deliveries
             WHERE user_id = :user_id AND unique_key = :unique_key AND channel = :channel
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'unique_key' => $uniqueKey,
            'channel' => $channel,
        ]);
        $row = $stmt->fetch();

        if (!is_array($row) || $row['status'] === 'SENT' || (int) $row['attempts'] >= 3) {
            return null;
        }

        $this->pdo->prepare(
            'UPDATE notification_channel_deliveries
             SET attempts = attempts + 1, status = "PENDING", updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        )->execute(['id' => $row['id']]);

        return $row;
    }

    private function finish(string $id, bool $ok, ?string $error): void
    {
        $this->pdo->prepare(
            'UPDATE notification_channel_deliveries
             SET status = :status,
                 last_error = :last_error,
                 sent_at = CASE WHEN :sent = 1 THEN UTC_TIMESTAMP() ELSE sent_at END,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        )->execute([
            'status' => $ok ? 'SENT' : 'FAILED',
            'last_error' => $error === null ? null : mb_substr($error, 0, 2000),
            'sent' => $ok ? 1 : 0,
            'id' => $id,
        ]);
    }

    private function absoluteUrl(?string $path): ?string
    {
        $path = trim((string) ($path ?? ''));
        $base = rtrim(trim($this->appUrl), '/');

        if ($path === '' || $base === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        return $base . $path;
    }
}

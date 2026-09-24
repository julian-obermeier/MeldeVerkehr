<?php

declare(strict_types=1);

namespace MeldeVerkehr\Operations;

use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\Uuid;
use PDO;

final class NotificationService
{
    private const EVENT_KEYS = [
        'CASE_TASK_OPEN',
        'CASE_DEADLINE_OPEN',
        'CASE_STATUS_DELIVERY_FAILED',
        'CASE_STATUS_USER_ACTION_REQUIRED',
        'CASE_STATUS_AUTHORITY_REPLY',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly SecretCipher $cipher,
        private readonly ?NotificationDeliveryService $delivery = null
    ) {
    }

    public function syncForUser(string $userId): int
    {
        $created = 0;

        $taskStmt = $this->pdo->prepare(
            'SELECT t.id, t.case_id, t.title, t.due_at, c.public_number
             FROM case_tasks t
             INNER JOIN cases c ON c.id = t.case_id
             WHERE c.user_id = :user_id AND t.status = "OPEN"'
        );
        $taskStmt->execute(['user_id' => $userId]);
        foreach ($taskStmt->fetchAll() as $row) {
            $created += $this->create(
                $userId,
                (string) $row['case_id'],
                'CASE_TASK_OPEN',
                'task:' . $row['id'],
                $row['due_at'] !== null ? 'HIGH' : 'NORMAL',
                'Aufgabe offen – ' . $row['public_number'],
                (string) $row['title'],
                '/cases/' . rawurlencode((string) $row['case_id']) . '/communication'
            ) ? 1 : 0;
        }

        $deadlineStmt = $this->pdo->prepare(
            'SELECT d.id, d.case_id, d.deadline_type, d.due_at, c.public_number,
                    TIMESTAMPDIFF(HOUR, UTC_TIMESTAMP(), d.due_at) AS hours_left
             FROM case_deadlines d
             INNER JOIN cases c ON c.id = d.case_id
             WHERE c.user_id = :user_id AND d.status = "OPEN"'
        );
        $deadlineStmt->execute(['user_id' => $userId]);
        foreach ($deadlineStmt->fetchAll() as $row) {
            $hours = (int) $row['hours_left'];
            $priority = $hours <= 48 ? 'URGENT' : 'HIGH';
            $created += $this->create(
                $userId,
                (string) $row['case_id'],
                'CASE_DEADLINE_OPEN',
                'deadline:' . $row['id'],
                $priority,
                'Frist – ' . $row['public_number'],
                'Frist ' . $row['deadline_type'] . ' bis ' . $row['due_at'],
                '/cases/' . rawurlencode((string) $row['case_id']) . '/communication'
            ) ? 1 : 0;
        }

        $caseStmt = $this->pdo->prepare(
            'SELECT id, public_number, status
             FROM cases
             WHERE user_id = :user_id
               AND status IN ("DELIVERY_FAILED","USER_ACTION_REQUIRED","AUTHORITY_REPLY")'
        );
        $caseStmt->execute(['user_id' => $userId]);
        foreach ($caseStmt->fetchAll() as $row) {
            $event = 'CASE_STATUS_' . $row['status'];
            $priority = $row['status'] === 'DELIVERY_FAILED' ? 'URGENT' : 'HIGH';
            $created += $this->create(
                $userId,
                (string) $row['id'],
                $event,
                'case-status:' . $row['id'] . ':' . $row['status'],
                $priority,
                'Vorgang ' . $row['public_number'],
                'Status: ' . $row['status'],
                '/cases/' . rawurlencode((string) $row['id'])
            ) ? 1 : 0;
        }

        return $created;
    }

    public function create(
        string $userId,
        ?string $caseId,
        string $eventKey,
        ?string $uniqueKey,
        string $priority,
        string $title,
        ?string $body,
        ?string $actionUrl
    ): bool {
        $priority = strtoupper(trim($priority));
        if (!in_array($priority, ['LOW','NORMAL','HIGH','URGENT'], true)) {
            throw new \InvalidArgumentException('Ungültige Notification-Priorität.');
        }

        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 255) {
            throw new \InvalidArgumentException('Notification-Titel ist ungültig.');
        }

        $eventKey = mb_substr(trim($eventKey), 0, 100);
        $preference = $this->preference($userId, $eventKey);
        $createdInApp = false;

        if ($preference['in_app']) {
            try {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO user_notifications
                     (id, user_id, case_id, event_key, unique_key, priority, title,
                      body_encrypted, action_url, created_at, read_at)
                     VALUES
                     (:id, :user_id, :case_id, :event_key, :unique_key, :priority, :title,
                      :body, :action_url, UTC_TIMESTAMP(), NULL)'
                );
                $stmt->execute([
                    'id' => Uuid::v4(),
                    'user_id' => $userId,
                    'case_id' => $caseId,
                    'event_key' => $eventKey,
                    'unique_key' => $uniqueKey === null ? null : mb_substr($uniqueKey, 0, 190),
                    'priority' => $priority,
                    'title' => $title,
                    'body' => $body === null || trim($body) === '' ? null : $this->cipher->encrypt(trim($body)),
                    'action_url' => $this->safeActionUrl($actionUrl),
                ]);
                $createdInApp = true;
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000' || $uniqueKey === null) {
                    throw $e;
                }
            }
        }

        $delivered = $this->delivery?->dispatch(
            $userId,
            $eventKey,
            $uniqueKey,
            $priority,
            $title,
            $body,
            $this->safeActionUrl($actionUrl),
            $preference['email'],
            $preference['push']
        ) ?? 0;

        return $createdInApp || $delivered > 0;
    }

    public function list(string $userId, bool $unreadOnly = false, int $limit = 100): array
    {
        $limit = max(1, min(250, $limit));
        $sql =
            'SELECT id, case_id, event_key, priority, title, body_encrypted,
                    action_url, created_at, read_at
             FROM user_notifications
             WHERE user_id = :user_id';

        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL';
        }

        $sql .= ' ORDER BY read_at IS NULL DESC,
                  FIELD(priority,"URGENT","HIGH","NORMAL","LOW"),
                  created_at DESC
                  LIMIT ' . $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['body'] = $row['body_encrypted'] === null
                ? null
                : $this->cipher->decrypt((string) $row['body_encrypted']);
            unset($row['body_encrypted']);
        }
        unset($row);

        return $rows;
    }

    public function latestPushPayload(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, title, body_encrypted, action_url, sent_at
             FROM notification_channel_deliveries
             WHERE user_id = :user_id
               AND channel = "PUSH"
               AND status = "SENT"
             ORDER BY sent_at DESC, updated_at DESC
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'title' => (string) $row['title'],
            'body' => $row['body_encrypted'] === null
                ? null
                : $this->cipher->decrypt((string) $row['body_encrypted']),
            'action_url' => $row['action_url'],
            'sent_at' => $row['sent_at'],
        ];
    }

    public function unreadCount(string $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM user_notifications
             WHERE user_id = :user_id AND read_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function markRead(string $userId, string $notificationId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_notifications
             SET read_at = COALESCE(read_at, UTC_TIMESTAMP())
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute(['id' => $notificationId, 'user_id' => $userId]);

        if ($stmt->rowCount() !== 1) {
            $check = $this->pdo->prepare(
                'SELECT COUNT(*) FROM user_notifications
                 WHERE id = :id AND user_id = :user_id'
            );
            $check->execute(['id' => $notificationId, 'user_id' => $userId]);
            if ((int) $check->fetchColumn() === 0) {
                throw new \DomainException('Benachrichtigung nicht gefunden.');
            }
        }
    }

    public function markAllRead(string $userId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_notifications
             SET read_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id AND read_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->rowCount();
    }

    public function eventKeys(): array
    {
        return self::EVENT_KEYS;
    }

    public function preferences(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT event_key, in_app_enabled, email_enabled, push_enabled
             FROM user_notification_preferences
             WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        $stored = [];
        foreach ($stmt->fetchAll() as $row) {
            $stored[(string) $row['event_key']] = [
                'in_app' => (bool) $row['in_app_enabled'],
                'email' => (bool) $row['email_enabled'],
                'push' => (bool) $row['push_enabled'],
            ];
        }

        $result = [];
        foreach (self::EVENT_KEYS as $eventKey) {
            $result[$eventKey] = $stored[$eventKey] ?? [
                'in_app' => true,
                'email' => true,
                'push' => true,
            ];
        }

        return $result;
    }

    public function preference(string $userId, string $eventKey): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT in_app_enabled, email_enabled, push_enabled
             FROM user_notification_preferences
             WHERE user_id = :user_id AND event_key = :event_key LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'event_key' => $eventKey]);
        $row = $stmt->fetch();

        return is_array($row)
            ? [
                'in_app' => (bool) $row['in_app_enabled'],
                'email' => (bool) $row['email_enabled'],
                'push' => (bool) $row['push_enabled'],
            ]
            : ['in_app' => true, 'email' => true, 'push' => true];
    }

    public function savePreference(
        string $userId,
        string $eventKey,
        bool $inApp,
        bool $email,
        bool $push
    ): void {
        $this->pdo->prepare(
            'INSERT INTO user_notification_preferences
             (user_id, event_key, in_app_enabled, email_enabled, push_enabled, updated_at)
             VALUES
             (:user_id, :event_key, :in_app, :email, :push, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                in_app_enabled = VALUES(in_app_enabled),
                email_enabled = VALUES(email_enabled),
                push_enabled = VALUES(push_enabled),
                updated_at = UTC_TIMESTAMP()'
        )->execute([
            'user_id' => $userId,
            'event_key' => mb_substr(trim($eventKey), 0, 100),
            'in_app' => $inApp ? 1 : 0,
            'email' => $email ? 1 : 0,
            'push' => $push ? 1 : 0,
        ]);
    }

    private function inAppEnabled(string $userId, string $eventKey): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT in_app_enabled FROM user_notification_preferences
             WHERE user_id = :user_id AND event_key = :event_key LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'event_key' => $eventKey]);
        $value = $stmt->fetchColumn();

        return $value === false ? true : (bool) $value;
    }

    private function safeActionUrl(?string $url): ?string
    {
        $url = trim((string) ($url ?? ''));
        if ($url === '') {
            return null;
        }

        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            throw new \InvalidArgumentException('Notification-Ziel muss eine lokale URL sein.');
        }

        return mb_substr($url, 0, 700);
    }
}

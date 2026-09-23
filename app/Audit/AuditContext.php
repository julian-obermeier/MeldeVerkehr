<?php

declare(strict_types=1);

namespace MeldeVerkehr\Audit;

final class AuditContext
{
    public static function requestMetadata(string $appKey): array
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        return [
            'ip_hash' => $ip === '' ? null : hash_hmac('sha256', $ip, $appKey),
            'user_agent_hash' => $agent === '' ? null : hash('sha256', $agent),
            'session_id_hash' => session_id() === '' ? null : hash('sha256', session_id()),
        ];
    }
}

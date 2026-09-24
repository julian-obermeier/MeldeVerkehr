<?php

declare(strict_types=1);

use MeldeVerkehr\Support\Env;

return [
    'push_vapid_public_key' => Env::get('PUSH_VAPID_PUBLIC_KEY', ''),
    'push_vapid_private_key' => Env::get('PUSH_VAPID_PRIVATE_KEY', ''),
    'push_vapid_subject' => Env::get('PUSH_VAPID_SUBJECT', ''),
    'push_ttl' => (int) (Env::get('PUSH_TTL', '120') ?? 120),
];

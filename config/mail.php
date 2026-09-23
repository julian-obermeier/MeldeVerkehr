<?php

declare(strict_types=1);

use MeldeVerkehr\Support\Env;

return [
    'from_address' => Env::get('MAIL_FROM_ADDRESS', ''),
    'from_name' => Env::get('MAIL_FROM_NAME', 'MeldeVerkehr'),
    'host' => Env::get('MAIL_HOST', ''),
    'port' => (int) (Env::get('MAIL_PORT', '587') ?? 587),
    'username' => Env::get('MAIL_USERNAME', ''),
    'password' => Env::get('MAIL_PASSWORD', ''),
    'encryption' => Env::get('MAIL_ENCRYPTION', 'tls'),
];

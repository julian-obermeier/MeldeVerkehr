<?php

declare(strict_types=1);

use MeldeVerkehr\Support\Env;

return [
    'reply_domain' => strtolower((string) Env::get('COMM_REPLY_DOMAIN', 'reply.invalid')),
    'reply_local_prefix' => strtolower((string) Env::get('COMM_REPLY_LOCAL_PREFIX', 'reply')),
    'inbound_enabled' => filter_var(Env::get('COMM_INBOUND_ENABLED', 'false'), FILTER_VALIDATE_BOOL),
    'imap_host' => (string) Env::get('IMAP_HOST', ''),
    'imap_port' => (int) (Env::get('IMAP_PORT', '993') ?? 993),
    'imap_encryption' => strtolower((string) Env::get('IMAP_ENCRYPTION', 'ssl')),
    'imap_username' => (string) Env::get('IMAP_USERNAME', ''),
    'imap_password' => (string) Env::get('IMAP_PASSWORD', ''),
    'imap_folder' => (string) Env::get('IMAP_FOLDER', 'INBOX'),
];

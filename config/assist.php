<?php

declare(strict_types=1);

use MeldeVerkehr\Support\Env;

return [
    'provider' => strtolower((string) Env::get('ASSIST_PROVIDER', 'disabled')),
    'endpoint' => (string) Env::get('ASSIST_ENDPOINT', ''),
    'api_key' => (string) Env::get('ASSIST_API_KEY', ''),
];

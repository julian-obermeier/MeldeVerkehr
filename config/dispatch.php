<?php

declare(strict_types=1);

use MeldeVerkehr\Support\Env;

return [
    'transport' => strtolower((string) Env::get('DISPATCH_TRANSPORT', 'dry_run')),
];

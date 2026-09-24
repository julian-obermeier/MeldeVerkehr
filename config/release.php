<?php

declare(strict_types=1);

use MeldeVerkehr\Support\Env;

return [
    'backup_max_file_bytes' => max(1048576, (int) (Env::get('BACKUP_MAX_FILE_BYTES', '52428800') ?? '52428800')),
    'api_rate_limit_attempts' => max(10, (int) (Env::get('API_RATE_LIMIT_ATTEMPTS', '120') ?? '120')),
    'api_rate_limit_window_seconds' => max(60, (int) (Env::get('API_RATE_LIMIT_WINDOW_SECONDS', '60') ?? '60')),
    'maintenance_flag' => 'storage/app/maintenance.flag',
];

<?php

declare(strict_types=1);

use MeldeVerkehr\Support\Env;

$closed = trim((string) (Env::get('RETENTION_CLOSED_CASE_DAYS', '') ?? ''));
$exports = trim((string) (Env::get('RETENTION_EXPORT_DAYS', '7') ?? '7'));

return [
    'closed_case_days' => $closed === '' ? null : max(1, (int) $closed),
    'export_days' => $exports === '' ? 7 : max(1, (int) $exports),
    'automatic_case_deletion' => false,
];

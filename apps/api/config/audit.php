<?php

declare(strict_types=1);

/*
 * Audit (step 4.5a). Plain values only: the deploy caches the config (php artisan optimize).
 *
 * export_max_rows: the most rows GET /audit-log/export writes (422 audit.export.too_large beyond, before anything is written), so a file
 *   stays within the shared host's memory, time and temporary disk (the xlsx is written to a temporary file, then sent).
 */
return [
    'export_max_rows' => 50000,
];

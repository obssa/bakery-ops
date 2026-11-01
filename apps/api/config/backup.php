<?php

declare(strict_types=1);

/*
 * Nightly backups and the restore drill (phase-5 plan section 6, step 5.5b; ADR 0020; docs/runbook/backup-restore.md). Every value is
 * read from the server .env and survives `php artisan config:cache` (DeployableCachesTest).
 *
 *   path            BACKUP_PATH: the folder the dumps, their manifests and the files snapshots go to. Unset: `~/backups/bakery-ops` of
 *                   the account that runs the scheduler, outside the app folder, so the deploy's `rsync --delete` never sees it. A
 *                   path inside the app folder is refused.
 *   keep            BACKUP_KEEP: how many dumps retention keeps (30, one a night).
 *   max_mb          BACKUP_MAX_MB: the cap on the dumps and their manifests together (2048); the oldest go first, never the newest good one.
 *   drill_database  BACKUP_DRILL_DATABASE: the scratch database the weekly drill restores into, on the same server and for the same
 *                   database user as the app's (one Ramin creates once in cPanel, plan 10.2 Q8). Unset: the drill records `skipped`.
 *                   Never the app's own database: the drill refuses it.
 *   remote          BACKUP_REMOTE: an rclone remote ("name:folder") the dump, its manifest and the files snapshot are copied to after
 *                   a good backup. Unset (the default, plan 10.2 Q2): no off-site copy, nothing runs.
 *   binaries        the client programs, found on PATH unless named: mysqldump and mysql (the MariaDB clients of the live host and
 *                   of the API image), rclone (only when a remote is set).
 *   main_tables     the tables whose row counts a manifest records and a drill compares after the restore: ledger, money, shifts
 *                   and audit, none of them ever emptied by a job.
 */
return [
    'path' => env('BACKUP_PATH'),
    'keep' => (int) env('BACKUP_KEEP', 30),
    'max_mb' => (int) env('BACKUP_MAX_MB', 2048),
    'drill_database' => env('BACKUP_DRILL_DATABASE'),
    'remote' => env('BACKUP_REMOTE'),
    'binaries' => [
        'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
        'mysql' => env('BACKUP_MYSQL', 'mysql'),
        'rclone' => env('BACKUP_RCLONE', 'rclone'),
    ],
    'main_tables' => [
        'branches', 'users', 'items', 'locations', 'recipes',
        'stock_movements', 'stock_balances', 'stock_movement_reversals', 'item_cost_history',
        'purchases', 'transfers', 'production_batches',
        'orders', 'order_lines', 'order_events', 'payments', 'order_refunds', 'payment_refunds',
        'shift_instances', 'stock_counts', 'stock_count_lines', 'cash_declarations', 'handover_notes',
        'audit_log',
    ],
];

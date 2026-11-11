<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * phase-6/board-drag (Ramin 2026-11-11: a request that waits in Requested must not wait unseen): the inbox accepts production_overdue
 * (Notifications' NotifyProductionOverdue: a request nobody has started waits too long or is past its due time), as
 * 2026_11_11_100700 did for the ops types: the CHECK notifications_type_check is dropped and added again with the twenty types before it
 * and the new one. Only DDL; no row is touched. The same statements work on MariaDB 10.11 and PostgreSQL.
 */
return new class extends Migration
{
    private const array BEFORE = [
        'stock_low', 'stock_out', 'production_requested', 'production_assigned', 'batch_pending_confirmation', 'batch_done', 'transfer_requested',
        'transfer_decided', 'line_ready', 'line_rejected', 'count_submitted', 'variance_decided', 'chain_broken', 'payment_disputed', 'weekly_summary',
        'order_refunded', 'print_failed', 'backup_failed', 'backup_stale', 'drill_failed',
    ];

    private const array ADDED = ['production_overdue'];

    public function up(): void
    {
        $this->replace([...self::BEFORE, ...self::ADDED]);
    }

    public function down(): void
    {
        // a production_overdue line would break the narrower list: the way back removes those inbox lines first
        DB::table('notifications')->whereIn('type', self::ADDED)->delete();
        $this->replace(self::BEFORE);
    }

    /**
     * @param  list<string>  $types
     */
    private function replace(array $types): void
    {
        $list = implode(', ', array_map(static fn (string $type): string => "'{$type}'", $types));
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_check');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ({$list}))");
    }
};

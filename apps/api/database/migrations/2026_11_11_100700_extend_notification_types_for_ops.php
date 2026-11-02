<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Step 5.5c (phase-5 plan section 3 M6 and section 5): the inbox accepts the four types of the ops listeners, print_failed (Notifications'
 * NotifyPrintFailed: a receipt that will not be printed) and backup_failed, backup_stale and drill_failed (NotifyOps: the host's backups and
 * restore drill), as 2026_11_10_100100 did for order_refunded: the CHECK notifications_type_check is dropped and added again with the
 * sixteen types before it and the four new ones. Only DDL; no row is touched, and every existing line keeps a type the wider list holds.
 * The same statements work on MariaDB 10.11 and PostgreSQL.
 */
return new class extends Migration
{
    private const array BEFORE = [
        'stock_low', 'stock_out', 'production_requested', 'production_assigned', 'batch_pending_confirmation', 'batch_done', 'transfer_requested',
        'transfer_decided', 'line_ready', 'line_rejected', 'count_submitted', 'variance_decided', 'chain_broken', 'payment_disputed', 'weekly_summary',
        'order_refunded',
    ];

    private const array OPS = ['print_failed', 'backup_failed', 'backup_stale', 'drill_failed'];

    public function up(): void
    {
        $this->replace([...self::BEFORE, ...self::OPS]);
    }

    public function down(): void
    {
        // an ops line would break the narrower list: the way back removes those inbox lines first
        DB::table('notifications')->whereIn('type', self::OPS)->delete();
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

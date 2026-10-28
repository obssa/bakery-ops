<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Step 4.3d (phase-4 plan section 5, NotifyRefund): the inbox accepts `order_refunded`, the type of Notifications' NotifyRefund (a refund
 * paid back, told to the people who check the money), as 2026_11_02_100300 did for payment_disputed and 2026_11_07_100100 for
 * weekly_summary: the CHECK notifications_type_check is dropped and added again with the fifteen types before it and the new one. Only DDL;
 * no row is touched. The same statements work on MariaDB 10.11 and PostgreSQL.
 */
return new class extends Migration
{
    private const array BEFORE = [
        'stock_low', 'stock_out', 'production_requested', 'production_assigned', 'batch_pending_confirmation', 'batch_done', 'transfer_requested',
        'transfer_decided', 'line_ready', 'line_rejected', 'count_submitted', 'variance_decided', 'chain_broken', 'payment_disputed', 'weekly_summary',
    ];

    private const array REFUNDS = ['order_refunded'];

    public function up(): void
    {
        $this->replace([...self::BEFORE, ...self::REFUNDS]);
    }

    public function down(): void
    {
        // a refund line would break the narrower list: the way back removes those inbox lines first
        DB::table('notifications')->whereIn('type', self::REFUNDS)->delete();
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

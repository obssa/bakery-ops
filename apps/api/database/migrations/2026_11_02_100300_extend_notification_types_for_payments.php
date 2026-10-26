<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Step 4.3c (phase-4 plan section 5): the inbox accepts `payment_disputed`, the type of Notifications' NotifyPaymentDisputed. The CHECK
 * notifications_type_check is dropped and added again with the ten Phase 2 types, the three of Phase 3 (2026_10_24_130100) and the new one; the
 * rows are not touched. The same ALTER TABLE syntax works on MariaDB 10.11 and PostgreSQL.
 */
return new class extends Migration
{
    private const array BEFORE = [
        'stock_low', 'stock_out', 'production_requested', 'production_assigned', 'batch_pending_confirmation', 'batch_done', 'transfer_requested',
        'transfer_decided', 'line_ready', 'line_rejected', 'count_submitted', 'variance_decided', 'chain_broken',
    ];

    private const array PAYMENTS = ['payment_disputed'];

    public function up(): void
    {
        $this->replace([...self::BEFORE, ...self::PAYMENTS]);
    }

    public function down(): void
    {
        // a row of the new type would break the narrower list: the way back removes those inbox lines first, as the Phase 3 migration does
        DB::table('notifications')->whereIn('type', self::PAYMENTS)->delete();
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

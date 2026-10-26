<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 4.4d (phase-4 plan sections 5 and 10.2 R-L): the inbox accepts `weekly_summary`, the type of Notifications' NotifyWeeklySummary, as
 * step 4.3c's 2026_11_02_100300 did for payment_disputed: the CHECK notifications_type_check is dropped and added again with the ten Phase 2
 * types, the three of Phase 3, payment_disputed and the new one. The bodies grow from 500 to 1000 characters: the weekly summary's line
 * carries its figures, the names in the reader's language and the signed link to the week's PDF (about 180 characters). Only DDL; the rows
 * are not touched (widening a VARCHAR keeps every value). The same statements work on MariaDB 10.11 and PostgreSQL.
 */
return new class extends Migration
{
    private const array BEFORE = [
        'stock_low', 'stock_out', 'production_requested', 'production_assigned', 'batch_pending_confirmation', 'batch_done', 'transfer_requested',
        'transfer_decided', 'line_ready', 'line_rejected', 'count_submitted', 'variance_decided', 'chain_broken', 'payment_disputed',
    ];

    private const array WEEKLY = ['weekly_summary'];

    public function up(): void
    {
        $this->replace([...self::BEFORE, ...self::WEEKLY]);
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('body_en', 1000)->change();
            $table->string('body_am', 1000)->change();
        });
    }

    public function down(): void
    {
        // a weekly line would break the narrower list and may be longer than 500: the way back removes those inbox lines first
        DB::table('notifications')->whereIn('type', self::WEEKLY)->delete();
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('body_en', 500)->change();
            $table->string('body_am', 500)->change();
        });
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

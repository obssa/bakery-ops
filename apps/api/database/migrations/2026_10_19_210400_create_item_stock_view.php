<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * item_stock_view: on_hand, reserved and available per (branch, item, location) recomputed from stock_movements alone
 * (ADR 0002, note of 2026-10-19: a plain view, since MySQL / MariaDB have no materialized views). It is the
 * reconciliation oracle the Phase 0 gate compares with stock_balances (tests/Gate/LedgerPropertiesTest.php), never a
 * lock target and never the read path.
 *
 * The expressions mirror App\Modules\Inventory\Ledger\MovementReason, frozen here as this migration's DDL:
 *   - place: the one location a row names; for a transfer row (both ends) the sign picks the side;
 *   - on_hand: qty, except for reservation / reservation_release, which move only reserved;
 *   - reserved: qty for reservation / reservation_release, and for production_consume / sale with a reservation_ref.
 * CREATE OR REPLACE because migrate:fresh drops tables but not views. Portable to PostgreSQL as written.
 */
return new class extends Migration
{
    public function up(): void
    {
        $place = 'CASE WHEN from_location_id IS NULL THEN to_location_id WHEN to_location_id IS NULL THEN from_location_id '
            .'WHEN qty < 0 THEN from_location_id ELSE to_location_id END';
        $onHand = "CASE WHEN reason IN ('reservation', 'reservation_release') THEN 0 ELSE qty END";
        $reserved = "CASE WHEN reason IN ('reservation', 'reservation_release') THEN qty "
            ."WHEN reason IN ('production_consume', 'sale') AND reservation_ref IS NOT NULL THEN qty ELSE 0 END";

        DB::statement(
            'CREATE OR REPLACE VIEW item_stock_view AS '
            ."SELECT branch_id, item_id, {$place} AS location_id, "
            ."CAST(SUM({$onHand}) AS DECIMAL(14,4)) AS on_hand, "
            ."CAST(SUM({$reserved}) AS DECIMAL(14,4)) AS reserved, "
            ."CAST(SUM({$onHand}) - SUM({$reserved}) AS DECIMAL(14,4)) AS available "
            ."FROM stock_movements GROUP BY branch_id, item_id, {$place}"
        );
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS item_stock_view');
    }
};

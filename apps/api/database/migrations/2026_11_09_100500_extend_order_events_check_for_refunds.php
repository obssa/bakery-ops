<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Step 4.1b, the refund half of the phase-4 plan's M9 (`extend_order_events_check`): the order timeline accepts `line.refunded` (one per
 * refunded line) and `order.refunded` (one per refund), written by Orders::refund. Step 4.3c's migration
 * (2026_11_02_100200_extend_order_events_check_for_payments) has run on the live database and is not edited: this one drops and adds the
 * CHECK order_events_event_check again with all 22 events (the 18 of Phase 1, the two payment events, the two refund events). DDL only:
 * no row is touched (every existing event is in the new list) and the append-only triggers stay as they are. The same ALTER TABLE syntax
 * works on MariaDB 10.11 and PostgreSQL.
 */
return new class extends Migration
{
    private const array BEFORE = [
        'order.created', 'order.lines_replaced', 'order.sent', 'order.send_refused', 'line.queued', 'line.accepted',
        'line.ready', 'line.rejected', 'line.served', 'line.voided', 'order.in_progress', 'order.ready', 'order.served',
        'order.settled', 'order.paid', 'order.closed', 'order.cancelled', 'order.voided',
        'payment.recorded', 'payment.voided',
    ];

    private const array REFUNDS = ['line.refunded', 'order.refunded'];

    public function up(): void
    {
        $this->replace([...self::BEFORE, ...self::REFUNDS]);
    }

    /**
     * The timeline is append-only, so a refund event can never be removed to make room for the narrower list: the way back is only possible
     * while there is none, and it says so instead of leaving the table without its CHECK.
     */
    public function down(): void
    {
        $rows = DB::table('order_events')->whereIn('event', self::REFUNDS)->count();
        if ($rows > 0) {
            throw new RuntimeException("order_events holds {$rows} refund event(s) and is append-only: this migration cannot be rolled back.");
        }
        $this->replace(self::BEFORE);
    }

    /**
     * @param  list<string>  $events
     */
    private function replace(array $events): void
    {
        $list = implode(', ', array_map(static fn (string $event): string => "'{$event}'", $events));
        DB::statement('ALTER TABLE order_events DROP CONSTRAINT order_events_event_check');
        DB::statement("ALTER TABLE order_events ADD CONSTRAINT order_events_event_check CHECK (event IN ({$list}))");
    }
};

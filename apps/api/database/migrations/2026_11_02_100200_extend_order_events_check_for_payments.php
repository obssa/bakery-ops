<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Step 4.3c, the payment half of the phase-4 plan's M9 (`extend_order_events_check`): the order timeline accepts `payment.recorded` (Orders'
 * ApplyPayment writes it for every payment) and `payment.voided` (Orders' ReversePayment). Step 4.3c took the void side over from step 4.1b, which
 * waits for Ramin's answer on R-C; 4.1b adds `line.refunded` and `order.refunded` in a migration of its own, the same way. The CHECK
 * order_events_event_check is dropped and added again with the 18 Phase 1 events plus the two. DDL only: no row is touched and the append-only
 * triggers stay as they are (tests/Invariants/AppendOnlyTest.php). The same ALTER TABLE syntax works on MariaDB 10.11 and PostgreSQL.
 */
return new class extends Migration
{
    private const array PHASE_1 = [
        'order.created', 'order.lines_replaced', 'order.sent', 'order.send_refused', 'line.queued', 'line.accepted',
        'line.ready', 'line.rejected', 'line.served', 'line.voided', 'order.in_progress', 'order.ready', 'order.served',
        'order.settled', 'order.paid', 'order.closed', 'order.cancelled', 'order.voided',
    ];

    private const array PAYMENTS = ['payment.recorded', 'payment.voided'];

    public function up(): void
    {
        $this->replace([...self::PHASE_1, ...self::PAYMENTS]);
    }

    /**
     * The timeline is append-only, so a row of a payment event can never be removed to make room for the narrower list: the way back is only
     * possible while there is none, and it says so instead of leaving the table without its CHECK.
     */
    public function down(): void
    {
        $rows = DB::table('order_events')->whereIn('event', self::PAYMENTS)->count();
        if ($rows > 0) {
            throw new RuntimeException("order_events holds {$rows} payment event(s) and is append-only: this migration cannot be rolled back.");
        }
        $this->replace(self::PHASE_1);
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

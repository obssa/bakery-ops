<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * One local day of a demo history as data (step 4.6a): what DemoDay plays between the Day shifts' start at 06:02 and their closing at
 * 14:05. Phase 3's two days (DayPlan::phaseThree) use the plain parts (waste, a transfer, three cash orders, a note, the planned
 * differences, the bar's closing left pending); the Phase 4 days (DayPlan::phaseFour) use the rest (the morning restock, the orders with
 * their payments, the stock-out, yesterday's disputes resolved).
 *
 *   $waste        07:30, [station, SKU, quantity in the base unit] (POST /stock/movements, reason waste)
 *   $transfers    08:30, [station, role asking, SKU, quantity in the base unit], confirmed by the manager at the store
 *   $night        the bakery's Night shift is planned at 08:30 (the bread baker on its roster) and starts at 22:05; it closes at
 *                 05:55 the next morning
 *   $cashOrders   Phase 3: [time, table, role of the waiter, [[SKU, quantity]]] taken, made, served and paid in cash at that instant
 *   $orders       Phase 4: the day's orders (PlannedOrder), each played over its own minutes
 *   $note         [time, station, key, body] a handover note on the station's shift
 *   $differences  station => [SKU => [difference in the base unit, variance reason code]] counted at the closing
 *   $cash         [amount added to the expected cash (negative: short), reason code] declared at the till's closing
 *   $pending      stations whose closing with a difference is left for the manager to decide
 *   $restock      Phase 4: the morning's purchases, transfers and production (DemoStock), 06:05 to 06:40
 *   $purchase     Phase 4: the weekly purchase is made this morning (Mondays, and the opening day)
 *   $stockOut     Phase 4: the product that runs out in the display case this morning and is made again from its automatic request
 *
 * @phpstan-type Difference array<string, array{0: string, 1: string}>
 */
final readonly class PlannedDay
{
    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $waste
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $transfers
     * @param  list<array{0: string, 1: string, 2: string, 3: list<array{0: string, 1: string}>}>  $cashOrders
     * @param  list<PlannedOrder>  $orders
     * @param  array{0: string, 1: string, 2: string, 3: string}|null  $note
     * @param  array<string, array<string, array{0: string, 1: string}>>  $differences
     * @param  array{0: string, 1: string}|null  $cash
     * @param  list<string>  $pending
     */
    public function __construct(
        public string $date,
        public array $waste = [],
        public array $transfers = [],
        public bool $night = false,
        public array $cashOrders = [],
        public array $orders = [],
        public ?array $note = null,
        public array $differences = [],
        public ?array $cash = null,
        public array $pending = [],
        public bool $restock = false,
        public bool $purchase = false,
        public ?string $stockOut = null,
    ) {}

    /** Saturday and Sunday (the busier days of the plan). */
    public function weekend(): bool
    {
        return in_array(DayPlan::weekdayOf($this->date), [6, 7], true);
    }
}

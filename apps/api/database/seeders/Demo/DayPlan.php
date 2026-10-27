<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;

/**
 * The days a demo history plays, as data and constants (step 4.6a; phase-4 plan section 8). DemoDay plays them; tests state them.
 *
 * phaseThree(): step 3.8's two days, exactly as Phase3Seeder wrote them before the day loop was extracted (waste at four stations, one
 * transfer, three cash orders a day, the bakery's Night shift across the first midnight, the kitchen's 500 g of flour, the bakery's three
 * loaves over, the bar's 250 ml of milk left for the manager and the till's 20.00 short).
 *
 * phaseFour(): the Phase 4 history, drawn once with mt_srand(SEED) so the same days come out on every run: WEEKDAY_ORDERS orders on a
 * weekday and WEEKEND_ORDERS on Saturday and Sunday between 06:45 and 12:50, paid over the CHANNEL_MIX (the bank share over
 * BANK_CHANNELS), SPLIT_PCT of the paid orders split (a digital part with its photo, the rest in cash), PAYMENT_VOID_PER_MILLE of them with
 * a digital part payment voided before the order is paid and the bill paid again in cash, ORDER_VOID_PCT of the sent orders voided,
 * VERIFIED_PCT of the digital payments verified and DISPUTED_PCT disputed by the manager (a third of those verified the next morning), and
 * REFUND_PCT refunded (zero until refunds exist). Every morning the stock is topped up (DemoStock: the weekly purchase on Mondays and on
 * the opening day, transfers out of the store, production batches); three planned days (STOCK_OUTS) let a display-case product run out,
 * and its automatic production request is made the same morning; five planned closings (DIFFERENCES) are counted with a difference the
 * manager approves; the bakery runs a Night shift every Friday (not when Friday is yesterday: that night would end today). The opening day
 * (the first one played, the day after the anchor) is a plain day before the window that the gate reads.
 */
final class DayPlan
{
    public const int SEED = 4040;

    /** Days of history in the window the gate reads, D-N to D-1 (DEMO_HISTORY_DAYS / bakery.demo.history_days overrides it). */
    public const int HISTORY_DAYS = 90;

    public const int WEEKDAY_ORDERS = 22;

    public const int WEEKEND_ORDERS = 34;

    /** @var array<string, int> percent of the payments that settle an order, by channel; `bank` is spread over BANK_CHANNELS */
    public const array CHANNEL_MIX = ['cash' => 45, 'telebirr' => 30, 'cbe_birr' => 10, 'bank' => 15];

    /** @var array<string, int> how the bank share is spread over the bank channels (weights adding up to the bank's 15) */
    public const array BANK_CHANNELS = ['cbe_bank' => 7, 'dashen' => 3, 'awash' => 2, 'abyssinia' => 2, 'coop' => 1];

    /** Percent of the paid orders that are split: a digital part with its photo, the rest in cash. */
    public const int SPLIT_PCT = 12;

    /** Per mille of the paid orders whose first (digital, part) payment is voided before the order is paid, then paid again in cash. */
    public const int PAYMENT_VOID_PER_MILLE = 15;

    /** Percent of the closed orders refunded. TODO(p4): 4.3d sets it to 2 and plays the refund (Payments::refund) as one more action. */
    public const int REFUND_PCT = 0;

    /** Percent of the digital payments the manager verifies at the end of the day. */
    public const int VERIFIED_PCT = 40;

    /** Percent of the digital payments the manager disputes at the end of the day (one in three is verified the next morning). */
    public const int DISPUTED_PCT = 3;

    /** Percent of the sent orders voided before anything is made. */
    public const int ORDER_VOID_PCT = 1;

    /**
     * The three planned stock-outs: [position in the window (0 = first day played, 1 = yesterday), product]. The product is not made
     * that morning; at about 10:30 an order takes every unit left in the display case, the stock-out opens an automatic production
     * request, and the baker makes it the same morning (accepted 11:00, finished 11:40, confirmed 11:45).
     *
     * @var list<array{0: float, 1: string}>
     */
    public const array STOCK_OUTS = [[0.25, 'P-PST-CROISSANT'], [0.55, 'P-CAK-BF-SLICE'], [0.82, 'P-BRD-MULMUL']];

    /** When the stock-out order is taken, and from when the product is ordered again. */
    public const string STOCK_OUT_AT = '10:30:00';

    public const string STOCK_BACK_AT = '11:50:00';

    /**
     * The five planned closings with a difference, approved by the manager: [position in the window, station, SKU or '@cash', difference
     * in the base unit (cash: in birr), reason code].
     *
     * @var list<array{0: float, 1: string, 2: string, 3: string, 4: string}>
     */
    public const array DIFFERENCES = [
        [0.12, 'kitchen', 'ING-TOMATO', '-500', 'spoilage'],
        [0.30, 'till', '@cash', '-20.00', 'miscount'],
        [0.50, 'bar', 'ING-MILK', '-250', 'spoilage'],
        [0.68, 'till', '@cash', '10.00', 'miscount'],
        [0.88, 'cake_room', 'ING-CREAM', '-200', 'spoilage'],
    ];

    /** The first order of a day and the latest an order is taken (its payment is done before 13:50). */
    public const string FIRST_ORDER_AT = '06:45:00';

    public const int ORDER_SPAN_MINUTES = 365;

    /** @var array<string, int> drinks, by weight (hot and cold) */
    private const array DRINKS = [
        'P-HOT-MACCHIATO' => 12, 'P-HOT-LATTE' => 15, 'P-HOT-CAPPUCCINO' => 12, 'P-HOT-BUNA' => 15, 'P-HOT-SHAI' => 6,
        'P-CLD-SPRIS' => 6, 'P-CLD-MANGO' => 8, 'P-CLD-AVOCADO' => 6, 'P-CLD-PAPAYA' => 4, 'P-CLD-WATER' => 8, 'P-CLD-COLA' => 5, 'P-CLD-ORANGE' => 3,
    ];

    /** @var array<string, int> made in the kitchen, by weight */
    private const array FOOD = ['P-SND-CHICKEN' => 25, 'P-SND-TUNA' => 15, 'P-SND-CLUB' => 20, 'P-SND-BURGER' => 20, 'P-PZA-MARGH' => 12, 'P-PZA-SPECIAL' => 8];

    /** @var array<string, int> from the display case, by weight */
    private const array BAKED = [
        'P-BRD-ROLL' => 25, 'P-BRD-BAGUETTE' => 10, 'P-BRD-MULMUL' => 15, 'P-PST-CROISSANT' => 20,
        'P-CAK-BF-SLICE' => 10, 'P-CAK-CHOC-SLICE' => 8, 'P-CAK-CHEESE' => 6, 'P-CAK-HONEY-SLICE' => 6,
    ];

    private const array TABLES = ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9', 'T10', 'TR1', 'TR2'];

    /** REFUND_PCT, read as the int it will be when step 4.3d sets it. */
    public static function refundPct(): int
    {
        return self::REFUND_PCT;
    }

    /** Days of history the window holds: bakery.demo.history_days (DEMO_HISTORY_DAYS), at least one. */
    public static function historyDays(): int
    {
        $days = config('bakery.demo.history_days', self::HISTORY_DAYS);

        return max(1, is_numeric($days) ? (int) $days : self::HISTORY_DAYS);
    }

    /** ISO day of the week of a local date (1 Monday .. 7 Sunday). */
    public static function weekdayOf(string $date): int
    {
        return CarbonImmutable::parse($date, DemoClock::TIMEZONE)->dayOfWeekIso;
    }

    /** How many orders the plan takes on a local date. */
    public static function ordersOn(string $date): int
    {
        return in_array(self::weekdayOf($date), [6, 7], true) ? self::WEEKEND_ORDERS : self::WEEKDAY_ORDERS;
    }

    /**
     * Step 3.8's two days of history after the anchor day.
     *
     * @return list<PlannedDay>
     */
    public static function phaseThree(string $first, string $second): array
    {
        $waste = [['kitchen', 'ING-TOMATO', '-300'], ['bar', 'ING-MANGO', '-400'], ['cake_room', 'ING-EGG', '-2'], ['bakery', 'ING-FLR-WHT', '-1000']];

        return [
            new PlannedDay(
                date: $first,
                waste: $waste,
                transfers: [['kitchen', 'chef', 'ING-OIL', '2000']],
                night: true,
                cashOrders: [
                    ['09:40', 'T1', 'waiter', [['P-HOT-LATTE', '2'], ['P-CLD-WATER', '1']]],
                    ['10:50', 'T2', 'waiter', [['P-HOT-CAPPUCCINO', '1'], ['P-CLD-COLA', '2']]],
                    ['12:10', 'T3', 'waiter', [['P-SND-CHICKEN', '1'], ['P-HOT-LATTE', '1']]],
                ],
                differences: ['kitchen' => ['ING-FLR-WHT' => ['-500', 'spoilage']]],
            ),
            new PlannedDay(
                date: $second,
                waste: $waste,
                transfers: [['bar', 'barista', 'ING-SUGAR', '2000']],
                cashOrders: [
                    ['09:40', 'T4', 'waiter', [['P-HOT-BUNA', '2'], ['P-CLD-ORANGE', '1']]],
                    ['10:50', 'T5', 'waiter', [['P-SND-TUNA', '1'], ['P-HOT-SHAI', '1']]],
                    ['12:10', 'T6', 'waiter', [['P-HOT-MACCHIATO', '2'], ['P-CLD-WATER', '2']]],
                ],
                note: ['13:30', 'kitchen', 'handover', 'The fryer oil was changed today; the walk-in door sticks, please push it shut. Chicken is low for tomorrow.'],
                differences: ['bakery' => ['@product' => ['3', 'miscount']], 'bar' => ['ING-MILK' => ['-250', 'spoilage']]],
                cash: ['-20.00', 'miscount'],
                pending: ['bar'],
            ),
        ];
    }

    /**
     * The Phase 4 history from $first (the opening day, the day after the anchor) to $last (yesterday), drawn with mt_srand(SEED).
     *
     * @param  list<string>  $waiters  the phones of the waiters who take the orders at the pad
     * @return list<PlannedDay>
     */
    public static function phaseFour(string $first, string $last, array $waiters): array
    {
        mt_srand(self::SEED);
        $dates = [];
        for ($day = CarbonImmutable::parse($first, DemoClock::TIMEZONE); $day->toDateString() <= $last; $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }
        $count = count($dates);
        $stockOuts = self::positions(array_map(static fn (array $s): float => $s[0], self::STOCK_OUTS), $count, 1);
        $differences = self::positions(array_map(static fn (array $d): float => $d[0], self::DIFFERENCES), $count, 0);

        /** @var list<list<PlannedOrder>> $orders */
        $orders = [];
        foreach ($dates as $i => $date) {
            $stockOut = null;
            foreach ($stockOuts as $k => $at) {
                if ($at === $i) {
                    $stockOut = self::STOCK_OUTS[$k][1];
                }
            }
            $orders[$i] = self::ordersFor($date, $waiters, $stockOut);
        }
        $orders = self::showcase($orders);
        // the last day's disputes have no next morning: they stay disputed
        foreach ($orders[$count - 1] as $k => $order) {
            if ($order->check === PlannedOrder::CHECK_DISPUTE_RESOLVED) {
                $orders[$count - 1][$k] = $order->with(check: PlannedOrder::CHECK_DISPUTE);
            }
        }

        $days = [];
        foreach ($dates as $i => $date) {
            $diff = [];
            $cash = null;
            foreach ($differences as $k => $at) {
                if ($at !== $i) {
                    continue;
                }
                [, $station, $sku, $qty, $reason] = self::DIFFERENCES[$k];
                if ($sku === '@cash') {
                    $cash ??= [$qty, $reason];
                } elseif (! isset($diff[$station])) {
                    $diff[$station] = [$sku => [$qty, $reason]];
                }
            }
            $stockOut = null;
            foreach ($stockOuts as $k => $at) {
                if ($at === $i) {
                    $stockOut = self::STOCK_OUTS[$k][1];
                }
            }
            $days[] = new PlannedDay(
                date: $date,
                night: self::weekdayOf($date) === 5 && $i < $count - 1,
                orders: $orders[$i],
                differences: $diff,
                cash: $cash,
                restock: true,
                purchase: $i === 0 || self::weekdayOf($date) === 1,
                stockOut: $stockOut,
            );
        }

        return $days;
    }

    /**
     * Where the planned positions fall in a window of $count days: round(position x (count - 1)), at least $min, each day used once
     * (a later one that collides moves on to the next free day, or is dropped when there is none).
     *
     * @param  list<float>  $fractions
     * @return array<int, int> index in $fractions => day index
     */
    private static function positions(array $fractions, int $count, int $min): array
    {
        $taken = [];
        $out = [];
        foreach ($fractions as $k => $fraction) {
            $at = max(min($min, $count - 1), (int) round($fraction * ($count - 1)));
            while ($at < $count && in_array($at, $taken, true)) {
                $at++;
            }
            if ($at >= $count) {
                continue;
            }
            $taken[] = $at;
            $out[$k] = $at;
        }

        return $out;
    }

    /**
     * @param  list<string>  $waiters
     * @return list<PlannedOrder>
     */
    private static function ordersFor(string $date, array $waiters, ?string $stockOut): array
    {
        $count = self::ordersOn($date);
        $slot = intdiv(self::ORDER_SPAN_MINUTES, $count);
        $start = CarbonImmutable::parse("{$date} ".self::FIRST_ORDER_AT, DemoClock::TIMEZONE);
        $orders = [];
        $stockOutTaken = false;
        for ($k = 0; $k < $count; $k++) {
            $at = $start->addMinutes($k * $slot + mt_rand(0, max(0, $slot - 2)))->addSeconds(mt_rand(0, 59));
            $time = $at->format('H:i:s');
            $table = self::TABLES[mt_rand(0, count(self::TABLES) - 1)];
            $waiter = $waiters[mt_rand(0, count($waiters) - 1)];
            $lines = self::lines();
            $voidOrder = mt_rand(1, 100) <= self::ORDER_VOID_PCT;
            $channel = self::channel(mt_rand(1, 100));
            $split = mt_rand(1, 100) <= self::SPLIT_PCT;
            $splitPct = mt_rand(30, 70);
            $voidPayment = mt_rand(1, 1000) <= self::PAYMENT_VOID_PER_MILLE;
            $roll = mt_rand(1, 100);
            $resolved = mt_rand(1, 3) === 1;
            $offsets = [mt_rand(1, 3), mt_rand(2, 5), mt_rand(6, 14), mt_rand(1, 3), mt_rand(4, 20)];
            // drawn on every order whatever REFUND_PCT says, so turning refunds on (4.3d) changes no other draw of the plan
            $refundRoll = mt_rand(1, 100);
            if ($voidPayment) {
                $split = false;
            }
            if ($voidOrder) {
                [$channel, $split, $voidPayment] = ['cash', false, false];
            }
            if (($split || $voidPayment) && $channel === 'cash') {
                $channel = self::digitalChannel(mt_rand(1, 55));
            }
            $check = match (true) {
                $channel === 'cash' || $voidOrder || $voidPayment => PlannedOrder::CHECK_NONE,
                $roll <= self::VERIFIED_PCT => PlannedOrder::CHECK_VERIFY,
                $roll <= self::VERIFIED_PCT + self::DISPUTED_PCT => $resolved ? PlannedOrder::CHECK_DISPUTE_RESOLVED : PlannedOrder::CHECK_DISPUTE,
                default => PlannedOrder::CHECK_NONE,
            };
            $refund = ! $voidOrder && $refundRoll <= self::refundPct();
            $order = new PlannedOrder($k + 1, $time, $table, $waiter, $lines, $voidOrder, $channel, $split, $splitPct, $voidPayment, $check, $refund, $offsets);

            if ($stockOut !== null) {
                if (! $stockOutTaken && $time >= self::STOCK_OUT_AT) {
                    // the stock-out order: every unit left, paid in cash, nothing else on it
                    $order = new PlannedOrder($k + 1, $time, $table, $waiter, [], false, 'cash', false, $splitPct, false, PlannedOrder::CHECK_NONE, false, $offsets, $stockOut);
                    $stockOutTaken = true;
                } elseif ($time < self::STOCK_BACK_AT) {
                    // the product is not sold before the stock-out order nor while its batch is being made
                    $kept = array_values(array_filter($lines, static fn (array $line): bool => $line[0] !== $stockOut));
                    $order = $order->with(lines: $kept === [] ? [['P-HOT-BUNA', '1']] : $kept);
                }
            }
            $orders[] = $order;
        }

        return $orders;
    }

    /**
     * One to four lines: a drink most of the time, food from the kitchen about half the time, something from the display case about
     * half the time.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function lines(): array
    {
        $lines = [];
        if (mt_rand(1, 100) <= 80) {
            $lines[self::pick(self::DRINKS)] = mt_rand(1, 100) <= 75 ? 1 : 2;
        }
        if (mt_rand(1, 100) <= 45) {
            $lines[self::pick(self::FOOD)] = mt_rand(1, 100) <= 85 ? 1 : 2;
        }
        if (mt_rand(1, 100) <= 55) {
            $sku = self::pick(self::BAKED);
            $lines[$sku] = mt_rand(1, $sku === 'P-BRD-ROLL' ? 4 : 2);
        }
        if ($lines === []) {
            $lines[self::pick(self::DRINKS)] = 1;
        }
        $out = [];
        foreach ($lines as $sku => $qty) {
            $out[] = [(string) $sku, (string) $qty];
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $weights
     */
    private static function pick(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $sku => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $sku;
            }
        }

        return (string) array_key_last($weights);
    }

    /** The channel for a roll of 1..100 over CHANNEL_MIX (cash first). */
    private static function channel(int $roll): string
    {
        foreach (self::CHANNEL_MIX as $channel => $share) {
            $roll -= $share;
            if ($roll <= 0) {
                return $channel === 'bank' ? self::bank() : $channel;
            }
        }

        return 'cash';
    }

    /** A digital channel for a roll of 1..55 over the digital part of CHANNEL_MIX. */
    private static function digitalChannel(int $roll): string
    {
        foreach (self::CHANNEL_MIX as $channel => $share) {
            if ($channel === 'cash') {
                continue;
            }
            $roll -= $share;
            if ($roll <= 0) {
                return $channel === 'bank' ? self::bank() : $channel;
            }
        }

        return 'telebirr';
    }

    private static function bank(): string
    {
        return self::pick(self::BANK_CHANNELS);
    }

    /**
     * Every kind the plan names happens at least once, however short the window: when the draw left one out, it is given to an order of
     * the second day (the first one played when there is only one): an order void, a split, a voided payment, a verified payment, and a
     * dispute that is verified the next morning (on the second-to-last day, so a morning follows it).
     *
     * @param  list<list<PlannedOrder>>  $days
     * @return list<list<PlannedOrder>>
     */
    private static function showcase(array $days): array
    {
        $all = array_merge(...$days);
        $has = static fn (callable $test): bool => array_filter($all, $test) !== [];
        $day = min(1, count($days) - 1);
        $free = static function (array $orders, int $skip = 0): ?int {
            foreach ($orders as $i => $order) {
                if ($order->stockOut === null && ! $order->voidOrder && ! $order->split && ! $order->voidPayment && $order->check === PlannedOrder::CHECK_NONE) {
                    if ($skip-- <= 0) {
                        return $i;
                    }
                }
            }

            return null;
        };
        $wants = [
            'void' => [! $has(static fn (PlannedOrder $o): bool => $o->voidOrder), static fn (PlannedOrder $o): PlannedOrder => $o->with(voidOrder: true, channel: 'cash', check: PlannedOrder::CHECK_NONE)],
            'split' => [! $has(static fn (PlannedOrder $o): bool => $o->split), static fn (PlannedOrder $o): PlannedOrder => $o->with(channel: 'telebirr', split: true, check: PlannedOrder::CHECK_VERIFY)],
            'void_payment' => [! $has(static fn (PlannedOrder $o): bool => $o->voidPayment), static fn (PlannedOrder $o): PlannedOrder => $o->with(channel: 'cbe_birr', voidPayment: true, check: PlannedOrder::CHECK_NONE)],
            'verify' => [! $has(static fn (PlannedOrder $o): bool => $o->check === PlannedOrder::CHECK_VERIFY), static fn (PlannedOrder $o): PlannedOrder => $o->with(channel: 'telebirr', check: PlannedOrder::CHECK_VERIFY)],
        ];
        foreach ($wants as $want) {
            [$missing, $make] = $want;
            if (! $missing) {
                continue;
            }
            $at = $free($days[$day]);
            if ($at !== null) {
                $days[$day][$at] = $make($days[$day][$at]);
            }
        }
        if (! $has(static fn (PlannedOrder $o): bool => $o->check === PlannedOrder::CHECK_DISPUTE_RESOLVED) && count($days) >= 2) {
            $before = count($days) - 2;
            $at = $free($days[$before], 1);
            if ($at !== null) {
                $days[$before][$at] = $days[$before][$at]->with(channel: 'dashen', check: PlannedOrder::CHECK_DISPUTE_RESOLVED);
            }
        }

        return array_map(static fn (array $orders): array => array_values($orders), $days);
    }
}

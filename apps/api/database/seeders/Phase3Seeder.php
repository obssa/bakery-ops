<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\Movement;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Transfer;
use App\Modules\Inventory\Transfers\ConfirmTransfer;
use App\Modules\Inventory\Transfers\RequestTransfer;
use App\Modules\Shifts\Actions\SaveTemplate;
use App\Modules\Shifts\Contracts\Shifts;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Modules\Shifts\Support\DefaultVarianceReasons;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoClock;
use Database\Seeders\Demo\DemoOrders;
use Database\Seeders\Demo\DemoSeedRefused;
use Database\Seeders\Demo\DemoShiftFlow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 of the demo bakery (step 3.8; phase-3 plan section 8): the shifts. It extends the Phase 0 to 2 world in the same branch and
 * never rewrites it. Everything goes through the module code the screens call (Shifts, Counts, the cash declaration, the co-signature,
 * the approval, the handover note, the Orders and Payments actions, the Ledger), never a raw insert into a shift, count, lock or ledger
 * table, so each row is audited, stamped and hash-chained exactly as the same action on the screens.
 *
 * Always (the "today" part): the Day and Night templates, the five variance reasons, a station worker on the roster of every running
 * shift, and, for a station with no running shift, a shift opened today from the last locked closing (the till's drawer declared
 * with it; a station whose last closing still waits for a decision starts without counting, which the opening count would be blocked
 * on). Nothing is ever written under a row the ledger has already shown.
 *
 * Only on a fresh demo branch (DemoClock moved the clock to 05:30 three local days ago before Phase 0 and the sample orders and
 * stock were written then): the history. The anchor day's shifts (carried, opened by DemoShifts) are closed at 21:30 and locked, the
 * first link of every place's chain; then D-2 and D-1: a Day shift per station (counted from the previous locked closing), plain
 * movements (waste, a transfer from the store) and three cash orders a day, the closings, the bakery's Night shift across midnight,
 * and the planned differences, each decided by the manager as the second person: 500 g of flour in the kitchen on D-2, 3 loaves over
 * at the bakery and 20.00 short in the drawer on D-1; the bar's closing of D-1 (250 ml of milk short) is left pending for the admin.
 * On a branch that already holds movements the history is not written, and the output says so.
 *
 * Idempotent: templates by name, reasons by code, shifts and orders and notes by deterministic client UUIDs, and a station that runs a
 * shift is left alone, so a second run writes nothing, not even an audit row. Queued listeners run synchronously for the run
 * (`queue.default` set to `sync` and put back), so the demo has real inbox lines without a worker. The demo branch must exist.
 */
final class Phase3Seeder extends Seeder
{
    private const string TZ = DemoClock::TIMEZONE;

    /** Station codes in the order they open and close. */
    private const array STATIONS = ['kitchen', 'bar', 'cake_room', 'bakery', 'till'];

    private DemoShiftFlow $flow;

    private DemoOrders $orders;

    /** @var array<string, ShiftTemplate> by name */
    private array $templates = [];

    /**
     * @throws DemoSeedRefused before anything is written: no demo branch yet
     */
    public function run(): void
    {
        $branch = Phase0Seeder::existingBranch() ?? throw DemoSeedRefused::phaseZeroMissing();

        $current = app(CurrentBranch::class);
        $actor = app(ActorContext::class);
        $previousBranch = $current->isResolved() ? $current->get() : null;
        $previousActor = $actor->toArray();
        $previousQueue = config('queue.default');
        $this->flow = new DemoShiftFlow($branch);
        $this->orders = new DemoOrders($branch, $this->flow);
        $this->templates = [];
        $history = DemoClock::anchored();

        config(['queue.default' => 'sync']);
        try {
            DB::transaction(function () use ($branch, $current, $history): void {
                $current->set($branch);
                $this->flow->as('admin');
                DefaultVarianceReasons::ensureFor($branch->id);
                $this->templates($branch);
                if ($history) {
                    $this->history($branch);
                    DemoClock::toReal();
                    $this->say('info', 'Phase 3: two days of locked history written, ending yesterday.');
                } else {
                    $this->say('warn', "Phase 3: history not written: the demo branch already holds movements, and the ledger is append-only, so nothing is written under them. Only today's part was done (templates, reasons, roster, today's shifts and note). Load a new demo branch (--branch) for the history.");
                }
                $this->today($branch);
            });
        } finally {
            DemoClock::toReal();
            config(['queue.default' => $previousQueue]);
            $this->restore($previousBranch, $previousActor);
        }
    }

    private function say(string $level, string $message): void
    {
        $this->command?->{$level}($message);
    }

    // ---------------------------------------------------------------------------------------------------- templates

    private function templates(Branch $branch): void
    {
        $this->flow->as('admin');
        $stations = Station::query()->whereIn('code', self::STATIONS)->pluck('id', 'code')->all();
        $specs = [
            'Day' => ['name_am' => 'ቀን', 'start_time' => '06:00', 'end_time' => '14:00', 'stations' => self::STATIONS, 'sort' => 1],
            'Night' => ['name_am' => 'ሌሊት', 'start_time' => '22:00', 'end_time' => '06:00', 'stations' => ['bakery'], 'sort' => 2],
        ];
        foreach ($specs as $name => $spec) {
            $template = ShiftTemplate::query()->withTrashed()->where('name_en', $name)->orderBy('id')->first();
            if (! $template instanceof ShiftTemplate) {
                $template = app(SaveTemplate::class)->create([
                    'name_en' => $name,
                    'name_am' => $spec['name_am'],
                    'start_time' => $spec['start_time'],
                    'end_time' => $spec['end_time'],
                    'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
                    'station_ids' => array_map(fn (string $code): int => (int) $stations[$code], $spec['stations']),
                    'sort' => $spec['sort'],
                ]);
            }
            $this->templates[$name] = $template;
        }
    }

    // ---------------------------------------------------------------------------------------------------- history

    private function at(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$time}", self::TZ)->utc();
    }

    private function localDate(int $daysAgo): string
    {
        return DemoClock::real()->setTimezone(self::TZ)->subDays($daysAgo)->toDateString();
    }

    private function history(Branch $branch): void
    {
        $d3 = $this->localDate(3);
        $d2 = $this->localDate(2);
        $d1 = $this->localDate(1);

        // the anchor day: the shifts DemoShifts opened for Phases 0 to 2 are closed with unchanged counts and locked
        DemoClock::set($this->at($d3, '21:30'));
        foreach (self::STATIONS as $code) {
            $shift = app(Shifts::class)->current($this->flow->station($code));
            if ($shift instanceof ShiftInstance) {
                $this->flow->close($shift, $code);
            }
        }

        $shifts = [];
        foreach ([$d2, $d1] as $date) {
            if ($date === $d1) {
                // the bakery's Night shift of D-2 ends this morning
                DemoClock::set($this->at($d1, '05:55'));
                $this->flow->close($shifts['night'], 'bakery');
            }
            DemoClock::set($this->at($date, '06:02'));
            foreach (self::STATIONS as $code) {
                $shifts[$code] = $this->flow->start($code, 'day', $date);
            }

            DemoClock::set($this->at($date, '07:30'));
            $this->waste($date);
            DemoClock::set($this->at($date, '08:30'));
            $this->transfer($branch, $date === $d2 ? ['kitchen', 'chef', 'ING-OIL', '2000'] : ['bar', 'barista', 'ING-SUGAR', '2000'], $date);
            if ($date === $d2) {
                // the Night shift is planned ahead, with the bread baker on its roster
                $this->flow->as('admin');
                $baker = $this->flow->person('bread_baker');
                $planned = app(Shifts::class)->schedule($this->flow->uuid("shift/bakery/{$date}/night-planned"), $this->flow->station('bakery'), $this->templates['Night'], $date, [$baker->id]);
                $shifts['night-planned'] = $planned->shift;
            }
            $times = ['09:40', '10:50', '12:10'];
            foreach ($this->dayOrders($date === $d2 ? 0 : 1) as $i => [$table, $waiter, $lines]) {
                DemoClock::set($this->at($date, $times[$i]));
                $this->orders->cashOrder("{$date}/{$i}", $table, $waiter, $lines);
            }
            if ($date === $d1) {
                DemoClock::set($this->at($date, '13:30'));
                $this->flow->note($shifts['kitchen'], 'kitchen', 'handover', 'The fryer oil was changed today; the walk-in door sticks, please push it shut. Chicken is low for tomorrow.');
            }

            DemoClock::set($this->at($date, '14:05'));
            foreach (self::STATIONS as $code) {
                $differences = match (true) {
                    $date === $d2 && $code === 'kitchen' => ['ING-FLR-WHT' => ['-500', 'spoilage']],
                    $date === $d1 && $code === 'bakery' => ['@product' => ['3', 'miscount']],
                    $date === $d1 && $code === 'bar' => ['ING-MILK' => ['-250', 'spoilage']],
                    default => [],
                };
                $cash = $date === $d1 && $code === 'till' ? ['-20.00', 'miscount'] : null;
                $this->flow->close($shifts[$code], $code, $differences, $cash, decide: ! ($date === $d1 && $code === 'bar'));
            }

            if ($date === $d2) {
                DemoClock::set($this->at($date, '22:05'));
                $shifts['night'] = $this->flow->start('bakery', 'night', $date, shiftId: $shifts['night-planned']->id);
            }
        }
    }

    /**
     * The day's three cash orders: [table, waiter role, [[sku, qty], ..]].
     *
     * @return list<array{0: string, 1: string, 2: list<array{0: string, 1: string}>}>
     */
    private function dayOrders(int $variant): array
    {
        return $variant === 0
            ? [
                ['T1', 'waiter', [['P-HOT-LATTE', '2'], ['P-CLD-WATER', '1']]],
                ['T2', 'waiter', [['P-HOT-CAPPUCCINO', '1'], ['P-CLD-COLA', '2']]],
                ['T3', 'waiter', [['P-SND-CHICKEN', '1'], ['P-HOT-LATTE', '1']]],
            ]
            : [
                ['T4', 'waiter', [['P-HOT-BUNA', '2'], ['P-CLD-ORANGE', '1']]],
                ['T5', 'waiter', [['P-SND-TUNA', '1'], ['P-HOT-SHAI', '1']]],
                ['T6', 'waiter', [['P-HOT-MACCHIATO', '2'], ['P-CLD-WATER', '2']]],
            ];
    }

    /** One waste movement per station, by the person who works there, through the Ledger as POST /stock/movements does. */
    private function waste(string $date): void
    {
        foreach ([['kitchen', 'ING-TOMATO', '-300'], ['bar', 'ING-MANGO', '-400'], ['cake_room', 'ING-EGG', '-2'], ['bakery', 'ING-FLR-WHT', '-1000']] as [$code, $sku, $qty]) {
            $uuid = $this->flow->uuid("waste/{$code}/{$date}");
            if (app(Stock::class)->movementByClientUuid($uuid) !== null) {
                continue;
            }
            $item = Item::query()->where('sku', $sku)->first();
            if (! $item instanceof Item) {
                continue;
            }
            $place = app(Locations::class)->stockLocationsOf($this->flow->station($code))[0] ?? null;
            if ($place === null) {
                continue;
            }
            $this->flow->as(DemoShiftFlow::STAFF[$code], $code);
            DB::transaction(function () use ($item, $place, $qty, $uuid): void {
                $movement = app(Ledger::class)->append(MovementIntent::of($item->id, $place->id, $qty, MovementReason::Waste, 'manual', null, null, $uuid));
                app(AuditedAction::class)->recordEvent('stock.movement.create', 'stock_movement', $movement->id, $movement->toArray() + ['note' => 'Dropped while preparing.', 'source' => 'demo_seed']);
            });
        }
    }

    /**
     * A transfer out of the store, asked by the worker at the destination and confirmed by the manager at the store.
     *
     * @param  array{0: string, 1: string, 2: string, 3: string}  $spec  [destination station, role, SKU, quantity in the base unit]
     */
    private function transfer(Branch $branch, array $spec, string $date): void
    {
        [$code, $role, $sku, $qty] = $spec;
        $item = Item::query()->where('sku', $sku)->where('is_active', true)->first();
        $to = app(Locations::class)->stockLocationsOf($this->flow->station($code))[0] ?? null;
        if (! $item instanceof Item || $to === null) {
            return;
        }
        $this->flow->as($role, $code);
        [$transfer, $created] = app(RequestTransfer::class)->handle($this->flow->uuid("transfer/{$code}/{$date}"), $item, Location::query()->findOrFail($to->id), Quantity::of($qty), null, null, null);
        if ($created) {
            $this->flow->as('manager', 'store');
            app(ConfirmTransfer::class)->handle(Transfer::query()->findOrFail($transfer->id));
        }
    }

    // ---------------------------------------------------------------------------------------------------- today

    /**
     * Today's shifts, from the last locked closing; the roster; the kitchen's handover note when the history did not leave one.
     */
    private function today(Branch $branch): void
    {
        $today = DemoClock::real()->setTimezone(self::TZ)->toDateString();
        foreach (self::STATIONS as $code) {
            $station = $this->flow->station($code);
            $running = app(Shifts::class)->current($station);
            if (! $running instanceof ShiftInstance) {
                // planned on the Day template for today, started by the admin whatever the hour (a window that has not begun is forced)
                $this->flow->as('admin');
                $staff = $this->flow->person(DemoShiftFlow::STAFF[$code]);
                $planned = app(Shifts::class)->schedule($this->flow->uuid("shift/{$code}/{$today}/planned"), $station, $this->templates['Day'], $today, [$staff->id]);
                $carry = $this->hasUnresolvedCount($station->id);
                $running = $this->flow->start($code, 'today', $today, $carry, $planned->shift->id, 'admin', true);
            }
            $this->flow->as(DemoShiftFlow::STAFF[$code], $code);
            app(Shifts::class)->join($running);
        }

        $kitchen = app(Shifts::class)->current($this->flow->station('kitchen'));
        if ($kitchen instanceof ShiftInstance) {
            $this->flow->note($kitchen, 'kitchen', 'handover', 'The fryer oil was changed; the walk-in door sticks, please push it shut.');
        }
        $this->flow->as('admin');
    }

    /** Does a count of the station's places wait for a co-signature or a decision? Its next opening count would be refused (rule R-A). */
    private function hasUnresolvedCount(int $stationId): bool
    {
        return DB::table('stock_counts')
            ->join('shift_instances', 'shift_instances.id', '=', 'stock_counts.shift_instance_id')
            ->where('shift_instances.station_id', $stationId)
            ->whereIn('stock_counts.status', ['awaiting_cosign', 'pending_approval'])
            ->exists();
    }

    /**
     * Put the request-scoped context back the way the caller had it (a console process or a test keeps running).
     *
     * @param  array{user_id: int|null, acting_user_id: int|null, shift_instance_id: int|null, station_id: int|null, device_id: int|null}  $actor
     */
    private function restore(?Branch $branch, array $actor): void
    {
        $current = app(CurrentBranch::class);
        $branch === null ? $current->clear() : $current->set($branch);

        $context = app(ActorContext::class);
        $context->reset();
        if ($actor['user_id'] !== null) {
            $context->authenticate($actor['user_id']);
        }
        $context->actingAs($actor['acting_user_id'])
            ->inShift($actor['shift_instance_id'])
            ->atStation($actor['station_id'])
            ->onDevice($actor['device_id']);
    }
}

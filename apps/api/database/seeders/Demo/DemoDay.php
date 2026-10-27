<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Transfer;
use App\Modules\Inventory\Transfers\ConfirmTransfer;
use App\Modules\Inventory\Transfers\RequestTransfer;
use App\Modules\Shifts\Contracts\Shifts;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Shared\Models\Branch;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The day loop of a demo history (step 4.6a, extracted from step 3.8's Phase3Seeder): plays one PlannedDay through the module code the
 * screens call, the way the people of the demo bakery work it.
 *
 *   05:55  the bakery's Night shift of the evening before is closed and counted (when one runs);
 *   06:02  every station's Day shift is started by its worker and its opening counted from the last locked closing;
 *   then   the day's steps in time order: the morning restock (DemoStock), waste at 07:30, a transfer at 08:30, the Night shift planned
 *          at 08:30, the orders (Phase 3's cash orders at their instant through DemoOrders, Phase 4's over their own minutes through
 *          DemoTill), the stock-out batch, the manager's checks of the payments, a handover note;
 *   14:05  every station's closing is counted ("nothing changed" except the planned differences and the till's planned cash difference),
 *          co-signed where an overseer is needed and decided by the manager, except a closing the plan leaves pending;
 *   22:05  the Night shift starts when one is planned.
 * closeAnchor() closes the shifts the Phase 0 to 2 seeders ran under on the anchor day (21:30), the first link of every place's chain;
 * today() is the part that always runs, in real time: today's shifts opened from the last locked closings, the roster, the kitchen's note.
 */
final class DemoDay
{
    private const string TZ = DemoClock::TIMEZONE;

    /** Station codes in the order they open and close. */
    public const array STATIONS = ['kitchen', 'bar', 'cake_room', 'bakery', 'till'];

    /** The bakery's Night shift: planned in the morning of its date, then started at 22:05; closed at 05:55 the next morning. */
    private ?ShiftInstance $nightPlanned = null;

    private ?ShiftInstance $night = null;

    /**
     * @param  array<string, ShiftTemplate>  $templates  by name (Day, Night)
     */
    public function __construct(
        private readonly DemoShiftFlow $flow,
        private readonly DemoOrders $orders,
        private readonly array $templates,
        private readonly ?DemoTill $till = null,
        private readonly ?DemoStock $stock = null,
    ) {}

    public function at(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$time}", self::TZ)->utc();
    }

    /** The anchor day's shifts (carried, opened by DemoShifts for Phases 0 to 2) are closed with unchanged counts and locked at 21:30. */
    public function closeAnchor(string $date): void
    {
        DemoClock::set($this->at($date, '21:30'));
        foreach (self::STATIONS as $code) {
            $shift = app(Shifts::class)->current($this->flow->station($code));
            if ($shift instanceof ShiftInstance) {
                $this->flow->close($shift, $code);
            }
        }
    }

    /**
     * Play one day; $upcoming are the days after it (the restock reads what they will need).
     *
     * @param  list<PlannedDay>  $upcoming
     */
    public function play(PlannedDay $day, array $upcoming = []): void
    {
        $date = $day->date;
        if ($this->night instanceof ShiftInstance) {
            // the bakery's Night shift of the evening before ends this morning
            DemoClock::set($this->at($date, '05:55'));
            $this->flow->close($this->night, 'bakery');
            $this->night = null;
        }

        DemoClock::set($this->at($date, '06:02'));
        /** @var array<string, ShiftInstance> $shifts */
        $shifts = [];
        foreach (self::STATIONS as $code) {
            $shifts[$code] = $this->flow->start($code, 'day', $date);
        }

        foreach ($this->steps($day, $upcoming, $shifts) as [$time, $step]) {
            DemoClock::set($this->at($date, $time));
            $step();
        }

        DemoClock::set($this->at($date, '14:05'));
        foreach (self::STATIONS as $code) {
            $this->flow->close(
                $shifts[$code],
                $code,
                $day->differences[$code] ?? [],
                $code === 'till' ? $day->cash : null,
                decide: ! in_array($code, $day->pending, true),
            );
        }

        if ($day->night) {
            DemoClock::set($this->at($date, '22:05'));
            $planned = $this->nightPlanned ?? throw new LogicException("The Night shift of {$date} was not planned.");
            $this->night = $this->flow->start('bakery', 'night', $date, shiftId: $planned->id);
            $this->nightPlanned = null;
        }
    }

    /** The bakery's Night shift that runs past the last day played (it ends this morning or later), if any. */
    public function runningNight(): ?ShiftInstance
    {
        return $this->night;
    }

    /**
     * The day's steps in time order (a stable sort: steps at the same minute keep the order they are listed in).
     *
     * @param  list<PlannedDay>  $upcoming
     * @param  array<string, ShiftInstance>  $shifts
     * @return list<array{0: string, 1: Closure(): void}>
     */
    private function steps(PlannedDay $day, array $upcoming, array $shifts): array
    {
        $date = $day->date;
        /** @var list<array{0: string, 1: Closure(): void}> $steps */
        $steps = [];
        if ($day->restock && $this->stock instanceof DemoStock) {
            array_push($steps, ...$this->stock->steps($day, $upcoming));
        }
        if ($day->waste !== []) {
            $steps[] = ['07:30', fn () => $this->waste($day->waste, $date)];
        }
        foreach ($day->transfers as $transfer) {
            $steps[] = ['08:30', fn () => $this->transfer($transfer, $date)];
        }
        if ($day->night) {
            $steps[] = ['08:30', fn () => $this->planNight($date)];
        }
        foreach ($day->cashOrders as $i => [$time, $table, $role, $lines]) {
            $steps[] = [$time, function () use ($date, $i, $table, $role, $lines): void {
                $this->orders->cashOrder("{$date}/{$i}", $table, $role, $lines);
            }];
        }
        if ($this->till instanceof DemoTill) {
            array_push($steps, ...$this->till->steps($day));
        }
        if ($day->note !== null) {
            [$time, $station, $key, $body] = $day->note;
            $steps[] = [$time, fn () => $this->flow->note($shifts[$station], $station, $key, $body)];
        }

        $order = array_keys($steps);
        usort($order, static fn (int $a, int $b): int => [self::seconds($steps[$a][0]), $a] <=> [self::seconds($steps[$b][0]), $b]);
        $sorted = [];
        foreach ($order as $i) {
            $sorted[] = $steps[$i];
        }

        return $sorted;
    }

    private static function seconds(string $time): int
    {
        $parts = array_map('intval', explode(':', $time));

        return $parts[0] * 3600 + $parts[1] * 60 + ($parts[2] ?? 0);
    }

    /** The Night shift of $date is planned ahead by the admin, with the bread baker on its roster. */
    private function planNight(string $date): void
    {
        $this->flow->as('admin');
        $baker = $this->flow->person('bread_baker');
        $planned = app(Shifts::class)->schedule($this->flow->uuid("shift/bakery/{$date}/night-planned"), $this->flow->station('bakery'), $this->templates['Night'], $date, [$baker->id]);
        $this->nightPlanned = $planned->shift;
    }

    /**
     * One waste movement per station, by the person who works there, through the Ledger as POST /stock/movements does.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $waste
     */
    public function waste(array $waste, string $date): void
    {
        foreach ($waste as [$code, $sku, $qty]) {
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
    public function transfer(array $spec, string $date): void
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
    public function today(Branch $branch): void
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
}

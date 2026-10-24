<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Contracts\StockLevel;
use App\Modules\Shifts\Actions\CosignCount;
use App\Modules\Shifts\Actions\SetCashDeclaration;
use App\Modules\Shifts\Actions\WriteHandoverNote;
use App\Modules\Shifts\Cash\CashDeclarations;
use App\Modules\Shifts\Cash\Denominations;
use App\Modules\Shifts\Contracts\Counts;
use App\Modules\Shifts\Contracts\Shifts;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\VarianceReason;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use LogicException;
use Ramsey\Uuid\Uuid;

/**
 * The moves of a shift as the people at a station make them, through the module code the screens call (Shifts, Counts, the cash
 * declaration, the co-signature and the handover note), for Phase3Seeder's days (step 3.8): start, open (count the opening, declare the
 * drawer), close (count the closing, with planned differences), settle (the overseer co-signs, the manager decides), note.
 *
 * Who does what is the demo's cast: the cashier at the till, the chef in the kitchen, the barista at the bar, the cake baker in the cake
 * room, the bread baker at the bakery; the cake baker co-signs the bakery (the overseer there), the manager co-signs the cake room and
 * decides every count with a difference (the second person: nobody approves what they counted themselves).
 */
final class DemoShiftFlow
{
    /** station code => the role that works it */
    public const array STAFF = ['till' => 'cashier', 'kitchen' => 'chef', 'bar' => 'barista', 'cake_room' => 'cake_baker', 'bakery' => 'bread_baker'];

    /** The most the cashier leaves in the drawer for the next shift; the rest goes to the bank. */
    public const string FLOAT_LEFT = '500.00';

    /** @var array<string, User> */
    private array $people = [];

    public function __construct(private readonly Branch $branch) {}

    /** Sign in as the demo person with this role, at a station when given, inside the shift that runs there. */
    public function as(string $role, ?string $stationCode = null): void
    {
        $station = $stationCode === null ? null : $this->station($stationCode);
        $context = app(ActorContext::class);
        $context->reset();
        $context->authenticate($this->person($role)->id)->atStation($station?->id);
        if ($station instanceof Station) {
            $context->inShift(app(Shifts::class)->current($station)?->id);
        }
    }

    public function person(string $role): User
    {
        if (isset($this->people[$role])) {
            return $this->people[$role];
        }
        foreach (DemoBakery::people() as $person) {
            if ($person['role'] === $role) {
                return $this->people[$role] = User::query()->withTrashed()->where('phone', $person['phone'])->firstOrFail();
            }
        }

        throw new LogicException("DemoBakery::people() has no {$role}.");
    }

    public function station(string $code): Station
    {
        return Station::query()->where('code', $code)->firstOrFail();
    }

    public function uuid(string $part): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "https://bakery-ops.orcamind.dev/demo-seed/{$this->branch->id}/phase3/{$part}")->toString();
    }

    /**
     * The station's shift is started by its worker and its opening counts are made: counted from the previous locked closing, or, with
     * $carry, opened at once without counting. $shiftId starts a scheduled instance; $by names who starts it (the admin may force it).
     */
    public function start(string $stationCode, string $slot, string $date, bool $carry = false, ?int $shiftId = null, ?string $by = null, bool $force = false): ShiftInstance
    {
        $by ??= self::STAFF[$stationCode];
        $this->as($by, $stationCode);
        $outcome = app(Shifts::class)->start($this->station($stationCode), $this->uuid("shift/{$stationCode}/{$date}/{$slot}"), $shiftId, null, $carry, $force);
        $shift = $outcome->shift;
        if ($outcome->created && $shift->status->value === 'opening') {
            $this->open($shift, $stationCode);
        }

        return ShiftInstance::query()->findOrFail($shift->id);
    }

    /** Count what the opening drafts ask for ("nothing changed" on the stock, the carried float in the drawer), hand them in, settle them. */
    public function open(ShiftInstance $shift, string $stationCode): void
    {
        $this->as(self::STAFF[$stationCode], $stationCode);
        foreach ($this->counts($shift, CountType::Opening) as $count) {
            if ($count->status !== CountStatus::Draft) {
                continue;
            }
            if ($this->cash()->isDrawer($count)) {
                $carried = $this->cash()->carriedFloat($count->location_id) ?? Money::of(self::FLOAT_LEFT);
                $this->declare($count, $carried->amount());
            } else {
                app(Counts::class)->setLines($count, [], true);
            }
            app(Counts::class)->submit($count);
        }
        $this->settle($shift, $stationCode);
    }

    /**
     * Close the shift and count the closing: "nothing changed" on every line except $differences (sku or '@product' => [delta in base
     * units, reason code]; the '@product' sku is the first product that has stock at the place) and the drawer's $cashDelta. A closing
     * with a difference waits for the decision and is left there when $decide is false.
     *
     * @param  array<string, array{0: string, 1: string}>  $differences
     * @param  array{0: string, 1: string}|null  $cashDelta  [amount to add to the declared cash (negative: short), reason code]
     */
    public function close(ShiftInstance $shift, string $stationCode, array $differences = [], ?array $cashDelta = null, bool $decide = true): void
    {
        $this->as(self::STAFF[$stationCode], $stationCode);
        $shift = app(Shifts::class)->close($shift);
        foreach ($this->counts($shift, CountType::Closing) as $count) {
            if ($count->status !== CountStatus::Draft) {
                continue;
            }
            if ($this->cash()->isDrawer($count)) {
                $row = $this->cash()->find($count) ?? $this->cash()->draft($count, $shift);
                $expected = $this->cash()->figures($count, $row)->expected;
                $counted = $cashDelta === null ? $expected : $expected->plus(Money::of($cashDelta[0]));
                $left = $counted->isGreaterThan(Money::of(self::FLOAT_LEFT)) ? Money::of(self::FLOAT_LEFT) : $counted;
                $this->declare($count, $counted->amount(), $left->amount(), $cashDelta === null ? null : $cashDelta[1]);
            } else {
                $this->countLines($count, $differences);
            }
            app(Counts::class)->submit($count);
        }
        if ($decide) {
            $this->settle($shift, $stationCode);
        }
    }

    /**
     * The overseer co-signs what awaits it, and the manager decides what waits for a decision (with a note).
     */
    public function settle(ShiftInstance $shift, string $stationCode): void
    {
        foreach ($this->counts($shift, null) as $count) {
            if ($count->status === CountStatus::AwaitingCosign) {
                $this->as($stationCode === 'bakery' ? 'cake_baker' : 'manager', $stationCode);
                app(CosignCount::class)->handle($count, 'Seen at the handover.');
                $count = StockCount::query()->findOrFail($count->id);
            }
            if ($count->status === CountStatus::PendingApproval) {
                DemoClock::tick(120);
                $this->as('manager', $stationCode);
                app(Counts::class)->approve($count, 'Checked with the team; the difference is explained.');
            }
        }
    }

    public function note(ShiftInstance $shift, string $stationCode, string $key, string $body): void
    {
        $this->as(self::STAFF[$stationCode], $stationCode);
        app(WriteHandoverNote::class)->handle($shift, $this->uuid("note/{$key}"), $body, null);
    }

    /** @return list<StockCount> the newest revision of each place's count of the type, in place order */
    private function counts(ShiftInstance $shift, ?CountType $type): array
    {
        return StockCount::query()->where('shift_instance_id', $shift->id)
            ->when($type !== null, fn ($q) => $q->where('type', $type?->value))
            ->orderBy('location_id')->orderBy('id')->get()->all();
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $differences
     */
    private function countLines(StockCount $count, array $differences): void
    {
        $lines = [];
        if ($differences !== []) {
            $live = app(Stock::class)->at($count->location_id);
            foreach ($differences as $sku => [$delta, $reason]) {
                $item = $this->itemFor($sku, $live);
                $expected = isset($live[$item]) ? $live[$item]->onHand : Quantity::zero();
                $counted = $expected->plus(Quantity::of($delta));
                $lines[] = [
                    'item_id' => $item,
                    'counted_qty' => $counted->toString(),
                    'reason_id' => (int) VarianceReason::query()->where('code', $reason)->value('id'),
                ];
            }
        }
        // a place that holds none of the item the difference names has no such line: it is counted at the place that does
        if ($lines !== [] && ! $this->placeHolds($count, $lines)) {
            $lines = [];
        }
        app(Counts::class)->setLines($count, $lines, true);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function placeHolds(StockCount $count, array $lines): bool
    {
        $live = app(Stock::class)->at($count->location_id);
        foreach ($lines as $line) {
            if (! isset($live[(int) $line['item_id']])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, StockLevel>  $live
     */
    private function itemFor(string $sku, array $live): int
    {
        if ($sku !== '@product') {
            return (int) Item::query()->where('sku', $sku)->value('id');
        }
        foreach (Item::query()->where('kind', 'product')->whereIn('id', array_keys($live))->orderBy('id')->get() as $item) {
            if (! $live[$item->id]->onHand->isZero()) {
                return $item->id;
            }
        }

        throw new LogicException('The place holds no product to count a difference on.');
    }

    /** Declare the drawer: the notes and coins that make $counted, the float that stays (a closing) and the reason for a difference. */
    private function declare(StockCount $count, string $counted, ?string $floatLeft = null, ?string $reason = null): void
    {
        $input = ['denominations' => $this->breakdown($counted)];
        if ($floatLeft !== null) {
            $input['float_left_total'] = $floatLeft;
        }
        if ($reason !== null) {
            $input['reason_id'] = (int) VarianceReason::query()->where('code', $reason)->value('id');
        }
        app(SetCashDeclaration::class)->handle($count, $input);
    }

    /**
     * The fewest notes and coins that make an amount, largest first.
     *
     * @return array<string, int>
     */
    private function breakdown(string $amount): array
    {
        $rest = BigDecimal::of($amount);
        $out = [];
        foreach (app(Denominations::class)->list() as $denomination) {
            $value = BigDecimal::of($denomination);
            $n = $rest->dividedBy($value, 0, RoundingMode::Down)->toInt();
            $out[$denomination] = $n;
            $rest = $rest->minus($value->multipliedBy($n));
        }
        if (! $rest->isZero()) {
            throw new LogicException("{$amount} cannot be made of the configured notes and coins.");
        }

        return $out;
    }

    private function cash(): CashDeclarations
    {
        return app(CashDeclarations::class);
    }

    /** The location ids are never needed by name here; kept for callers that want the drawer of a station. */
    public function drawerOf(string $stationCode): ?int
    {
        return app(Locations::class)->cashDrawerOf($this->station($stationCode))?->id;
    }
}

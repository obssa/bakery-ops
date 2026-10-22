<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Exceptions\InventoryError;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Actor\ActorContext;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Shifts\OpenShifts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeOpenShifts;

/*
 * The ledger's shift enforcement (F2, step 3.1b; phase-3 plan sections 2 and 10.1), as a table: every movement reason
 * against every kind of place, in every state of cover, with `ledger.require_shift_for_station_locations` on and off.
 *
 * The rule: with the flag on, Ledger::appendMany refuses the whole batch (409 inventory.shift.required {station_id,
 * location_id}, nothing written) when an intent changes on_hand at a location whose station tracks shifts, is not a
 * count's own adjustment (ref_type stock_count_line) and is not covered; covered = the actor carries a shift that is open,
 * or the location's station has a shift in status open. With the flag off OpenShifts is never asked. OpenShifts is the
 * Tests\Support\FakeOpenShifts double: Shifts does not exist yet.
 */

const ENF_ACTOR_SHIFT = 900;
const ENF_CLOSED_ACTOR_SHIFT = 901;

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);

    $this->stations = [
        'kitchen' => Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]),
        'till' => Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $b]),
        'store' => Station::factory()->forCode(StationCode::Store)->create(['branch_id' => $b, 'sort' => 5]),
        'bar' => Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $b, 'tracks_shifts' => false]),
    ];
    // place name => [location, whether its station tracks shifts]
    $this->places = [
        'store' => stockLocation($this->branch, LocationType::Store, $this->stations['store']),
        'kitchen' => stockLocation($this->branch, LocationType::Kitchen, $this->stations['kitchen']),
        'display case' => stockLocation($this->branch, LocationType::DisplayCase, $this->stations['till']),
        'cash drawer' => stockLocation($this->branch, LocationType::CashDrawer, $this->stations['till']),
        'bar (untracked)' => stockLocation($this->branch, LocationType::Bar, $this->stations['bar']),
        'no station' => stockLocation($this->branch, LocationType::Store),
    ];
    $this->tracked = ['store' => false, 'kitchen' => true, 'display case' => true, 'cash drawer' => true, 'bar (untracked)' => false, 'no station' => false];
    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $b, 'name_en' => 'Flour']);
    foreach ($this->places as $place) {
        stockIn($this->flour, $place, '1000');
    }
    $this->ledger = fn (): Ledger => app(Ledger::class);
    $this->actor = app(ActorContext::class);
    // every shift id the cases stamp, cover with or write a row under: since step 3.2a a stamp needs the shift_instances row behind it
    foreach ([600, 601, 602, 650, 700, 801, ENF_ACTOR_SHIFT, ENF_CLOSED_ACTOR_SHIFT] as $shiftId) {
        shiftRow($shiftId, $this->branch->id, $this->admin->id);
    }
});

/** Put the flag and the OpenShifts double in place. */
function enfSetUp(bool $flag, OpenShifts $double): void
{
    config(['ledger.require_shift_for_station_locations' => $flag]);
    app()->instance(OpenShifts::class, $double);
}

/**
 * The intents of a table row against a place. A settling or releasing intent needs a live reservation, so it is reserved
 * first with the flag off (reservations are exempt either way).
 *
 * @return list<MovementIntent>
 */
function enfIntents(object $t, string $reason, string $place): array
{
    $at = $t->places[$place];
    $item = $t->flour->id;
    $other = $t->places[$place === 'no station' ? 'bar (untracked)' : 'no station'];
    $ref = (string) Str::uuid();
    $reserve = function () use ($t, $item, $at, $ref): void {
        enfSetUp(false, new FakeOpenShifts);
        ($t->ledger)()->append(MovementIntent::of($item, $at->id, '1', MovementReason::Reservation, 'order_line', 1, $ref));
    };

    return match ($reason) {
        'purchase_in' => [MovementIntent::of($item, $at->id, '2', MovementReason::PurchaseIn, 'test')],
        'return' => [MovementIntent::of($item, $at->id, '2', MovementReason::Returned, 'test')],
        'production_output' => [MovementIntent::of($item, $at->id, '2', MovementReason::ProductionOutput, 'production_batch', 1)],
        'waste' => [MovementIntent::of($item, $at->id, '-2', MovementReason::Waste, 'test')],
        'sale' => [MovementIntent::of($item, $at->id, '-2', MovementReason::Sale, 'order_line', 1)],
        'production_consume' => [MovementIntent::of($item, $at->id, '-2', MovementReason::ProductionConsume, 'order_line', 1)],
        'count_adjustment up (by hand)' => [MovementIntent::of($item, $at->id, '2', MovementReason::CountAdjustment, 'manual')],
        'count_adjustment down (by hand)' => [MovementIntent::of($item, $at->id, '-2', MovementReason::CountAdjustment, 'manual')],
        'count_adjustment up (a count line)' => [MovementIntent::of($item, $at->id, '2', MovementReason::CountAdjustment, 'stock_count_line', 5)],
        'count_adjustment down (a count line)' => [MovementIntent::of($item, $at->id, '-2', MovementReason::CountAdjustment, 'stock_count_line', 6)],
        'reservation' => [MovementIntent::of($item, $at->id, '2', MovementReason::Reservation, 'order_line', 1, $ref)],
        'reservation_release' => (function () use ($reserve, $item, $at, $ref): array {
            $reserve();

            return [MovementIntent::of($item, $at->id, '-1', MovementReason::ReservationRelease, 'order_line', 1, $ref)];
        })(),
        'production_consume settling a reservation' => (function () use ($reserve, $item, $at, $ref): array {
            $reserve();

            return [MovementIntent::of($item, $at->id, '-1', MovementReason::ProductionConsume, 'order_line', 1, $ref)];
        })(),
        'sale settling a reservation' => (function () use ($reserve, $item, $at, $ref): array {
            $reserve();

            return [MovementIntent::of($item, $at->id, '-1', MovementReason::Sale, 'order_line', 1, $ref)];
        })(),
        'transfer out' => MovementIntent::transfer($item, $at->id, $other->id, '2', 'test'),
        'transfer in' => MovementIntent::transfer($item, $other->id, $at->id, '2', 'test'),
    };
}

/** Does an intent of this table row change on_hand, and is it a count's own correction? [changesOnHand, exemptCount] */
function enfKind(string $reason): array
{
    return [
        ! in_array($reason, ['reservation', 'reservation_release'], true),
        str_contains($reason, '(a count line)'),
    ];
}

dataset('enf reasons', [
    'purchase_in', 'return', 'production_output', 'waste', 'sale', 'production_consume',
    'count_adjustment up (by hand)', 'count_adjustment down (by hand)',
    'count_adjustment up (a count line)', 'count_adjustment down (a count line)',
    'reservation', 'reservation_release', 'production_consume settling a reservation', 'sale settling a reservation',
    'transfer out', 'transfer in',
]);

dataset('enf places', ['store', 'kitchen', 'display case', 'cash drawer', 'bar (untracked)', 'no station']);

it('refuses or writes as the rule says, for every reason at every place in every state of cover, flag on and off', function (string $reason, string $place) {
    [$changesOnHand, $countOwn] = enfKind($reason);
    $station = $this->stations[match ($place) {
        'kitchen' => 'kitchen',
        'display case', 'cash drawer' => 'till',
        'store' => 'store',
        'bar (untracked)' => 'bar',
        default => null,
    } ?? 'store'];
    $tracked = $this->tracked[$place];
    $location = $this->places[$place];

    // coverage: [what the actor carries, the shift running at this place's station]
    $covers = [
        'nothing' => [null, null],
        'actor shift open' => [ENF_ACTOR_SHIFT, null],
        'actor shift closed' => [ENF_CLOSED_ACTOR_SHIFT, null],
        'station shift open' => [null, 600],
        'both open' => [ENF_ACTOR_SHIFT, 600],
        'actor closed, station open' => [ENF_CLOSED_ACTOR_SHIFT, 600],
        'only another station open' => [null, 'elsewhere'],
    ];

    foreach ([false, true] as $flag) {
        foreach ($covers as $cover => [$actorShift, $stationShift]) {
            $running = [];
            if ($stationShift === 'elsewhere') {
                $running[$this->stations[$place === 'kitchen' ? 'till' : 'kitchen']->id] = 777;
            } elseif ($stationShift !== null && $place !== 'no station') {
                $running[$station->id] = $stationShift;
            }
            $intents = enfIntents($this, $reason, $place);
            $double = new FakeOpenShifts($running, [ENF_ACTOR_SHIFT]);
            enfSetUp($flag, $double);
            $this->actor->inShift($actorShift);
            $before = DB::table('stock_movements')->count();
            $label = ($flag ? 'flag on' : 'flag off')." / {$reason} / {$place} / cover: {$cover}";

            $enforced = $flag && $changesOnHand && ! $countOwn && $tracked;
            $covered = $actorShift === ENF_ACTOR_SHIFT || ($stationShift === 600);

            try {
                $written = ($this->ledger)()->appendMany($intents);
                $thrown = null;
            } catch (InventoryError $e) {
                $thrown = $e;
                $written = [];
            }

            if ($enforced && ! $covered) {
                expect($thrown)->toBeInstanceOf(InventoryError::class, $label)
                    ->and($thrown->messageKey)->toBe('inventory.shift.required', $label)
                    ->and($thrown->status)->toBe(409, $label)
                    ->and($thrown->extra)->toBe(['station_id' => $station->id, 'location_id' => $location->id], $label)
                    ->and(DB::table('stock_movements')->count())->toBe($before, $label);

                continue;
            }

            expect($thrown)->toBeNull($label)
                ->and(DB::table('stock_movements')->count())->toBe($before + count($intents), $label);
            // the stamp: the actor's shift as it always was, except that a row the station's shift alone covered (the actor's
            // own is not open) carries the station's
            $expectedStamp = $enforced && $actorShift !== ENF_ACTOR_SHIFT ? 600 : $actorShift;
            foreach ($written as $movement) {
                if ($movement->locationId === $location->id) {
                    expect($movement->shiftInstanceId)->toBe($expectedStamp, $label);
                }
            }
            if (! $flag) {
                expect($double->asked)->toBe([], $label.' (OpenShifts is never consulted)');
            }
        }
    }
})->with('enf reasons')->with('enf places')->group('module:inventory', 'unit');

it('asks OpenShifts nothing with the flag off or for a batch that needs no cover, and needs a shift only for what is enforced', function () {
    $double = new FakeOpenShifts;
    enfSetUp(true, $double);
    ($this->ledger)()->append(MovementIntent::of($this->flour->id, $this->places['store']->id, '1', MovementReason::PurchaseIn, 'test'));
    ($this->ledger)()->append(MovementIntent::of($this->flour->id, $this->places['kitchen']->id, '-1', MovementReason::CountAdjustment, 'stock_count_line', 3));

    expect($double->asked)->toBe([]);
})->group('module:inventory', 'unit');

it('needs every tracked station of a batch covered, refuses the first uncovered one named, and an actor shift covers them all', function () {
    $kitchen = $this->places['kitchen'];
    $display = $this->places['display case'];
    $batch = fn (): array => MovementIntent::transfer($this->flour->id, $kitchen->id, $display->id, '1', 'test');
    $before = DB::table('stock_movements')->count();

    // the kitchen is covered, the till is not: the whole batch is refused and names the till's display case
    enfSetUp(true, new FakeOpenShifts([$this->stations['kitchen']->id => 601]));
    $this->actor->inShift(null);
    try {
        ($this->ledger)()->appendMany($batch());
        $thrown = null;
    } catch (InventoryError $e) {
        $thrown = $e;
    }
    expect($thrown?->messageKey)->toBe('inventory.shift.required')
        ->and($thrown?->extra)->toBe(['station_id' => $this->stations['till']->id, 'location_id' => $display->id])
        ->and(DB::table('stock_movements')->count())->toBe($before);

    // both covered by their own station shifts: each row carries its own station's shift
    enfSetUp(true, new FakeOpenShifts([$this->stations['kitchen']->id => 601, $this->stations['till']->id => 602]));
    $rows = ($this->ledger)()->appendMany($batch());
    expect(array_map(fn ($m) => $m->shiftInstanceId, $rows))->toBe([601, 602]);

    // the actor's open shift covers both, and stamps both
    enfSetUp(true, new FakeOpenShifts([], [ENF_ACTOR_SHIFT]));
    $this->actor->inShift(ENF_ACTOR_SHIFT);
    $rows = ($this->ledger)()->appendMany($batch());
    expect(array_map(fn ($m) => $m->shiftInstanceId, $rows))->toBe([ENF_ACTOR_SHIFT, ENF_ACTOR_SHIFT]);
})->group('module:inventory', 'unit');

it('takes the shared lock on the shifts it relies on in ascending id order, inside the ledger transaction', function () {
    $double = new FakeOpenShifts([$this->stations['kitchen']->id => 801, $this->stations['till']->id => 700]);
    enfSetUp(true, $double);
    $this->actor->inShift(null);

    ($this->ledger)()->appendMany(MovementIntent::transfer($this->flour->id, $this->places['kitchen']->id, $this->places['display case']->id, '1', 'test'));

    $locked = array_values(array_filter($double->asked, fn (string $q): bool => str_starts_with($q, 'isOpen:')));
    expect($locked)->toBe(['isOpen:700', 'isOpen:801']);
})->group('module:inventory', 'unit');

it('refuses when the shift found closes between the look and the lock', function () {
    // the first look finds the shift open, the locked re-check no longer does: the station was frozen in between
    $double = new class implements OpenShifts
    {
        public function stampFor(int $stationId): ?int
        {
            return 650;
        }

        public function openAt(int $stationId, bool $lock = false): ?int
        {
            return $lock ? null : 650;
        }

        public function isOpen(int $shiftId, bool $lock = false): bool
        {
            return ! $lock;
        }

        public function currentFor(int $stationId): ?array
        {
            return null;
        }
    };
    enfSetUp(true, $double);
    $this->actor->inShift(null);
    $before = DB::table('stock_movements')->count();

    expect(fn () => ($this->ledger)()->append(MovementIntent::of($this->flour->id, $this->places['kitchen']->id, '1', MovementReason::PurchaseIn, 'test')))
        ->toThrow(InventoryError::class)
        ->and(DB::table('stock_movements')->count())->toBe($before);
})->group('module:inventory', 'unit');

it('ships the flag off in the config file and has the refusal in both languages', function () {
    expect(config('ledger.require_shift_for_station_locations'))->toBeFalse()
        ->and(trans('inventory.shift.required', [], 'en'))->not->toBe('inventory.shift.required')
        ->and(trans('inventory.shift.required', [], 'am'))->not->toBe('inventory.shift.required')
        ->and(trans('inventory.shift.required', [], 'am'))->not->toBe(trans('inventory.shift.required', [], 'en'));
})->group('module:inventory', 'unit');

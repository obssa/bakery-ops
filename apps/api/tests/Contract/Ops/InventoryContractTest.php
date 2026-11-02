<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Contracts\LedgerOracle;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Models\Branch;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\ScratchCopy;

/*
 * Contract: ops -> inventory (phase-5 plan section 2; manifest contracts["ops->inventory"], step 5.5b).
 *
 * Written BEFORE `Inventory\Contracts\LedgerOracle` exists: RED in the first commit of 5.5b because the class is missing. The restore
 * drill of App\Ops reads a restored database through a named connection and consumes only
 *   LedgerOracle::check(string $connection): LedgerCheck   ->ok(), ->toArray() = {ok, items, locations, mismatches}
 * where `items` and `locations` are how many distinct items and places the ledger holds, and each mismatch is
 *   {source, branch_id, item_id, location_id, movements: {on_hand, reserved}, recorded: {on_hand, reserved}}
 * `movements` is what the stock_movements rows add up to by the ledger's rule (ADR 0002), `recorded` what `source` (stock_balances,
 * or the item_stock_view the restore brought back) says, both decimal strings at scale 4; a pair at zero on both sides is no pair.
 * Mismatches are ordered by source, branch, item and place. The tamper cases play the person with write access to a restored copy:
 * the scratch database (Tests\Support\ScratchCopy) holds a copy of this test's rows, its triggers are dropped for a raw statement and
 * put back, and the default connection keeps the originals.
 */

uses()->group('contract:ops->inventory');

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    withActor(User::factory()->create(['branch_id' => $this->branch->id])->id);
    $this->flour = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id])->id;
    $this->sugar = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id])->id;
    $this->store = stockLocation($this->branch, LocationType::Store)->id;
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen)->id;

    // flour: 100 in at the store, 40 to the kitchen, 10 reserved there and 6 of them sold, 2.5 wasted at the store; sugar: 20 in, 1.25 counted out
    $ledger = app(Ledger::class);
    $ledger->appendMany([MovementIntent::of($this->flour, $this->store, '100.0000', MovementReason::PurchaseIn, 'contract')]);
    $ledger->appendMany(MovementIntent::transfer($this->flour, $this->store, $this->kitchen, '40.0000', 'contract'));
    $ref = '7d3c4b1e-0000-4000-a000-000000000001';
    $ledger->appendMany([MovementIntent::of($this->flour, $this->kitchen, '10.0000', MovementReason::Reservation, 'contract', null, $ref)]);
    $ledger->appendMany([MovementIntent::of($this->flour, $this->kitchen, '-6.0000', MovementReason::Sale, 'contract', null, $ref)]);
    $ledger->appendMany([MovementIntent::of($this->flour, $this->store, '-2.5000', MovementReason::Waste, 'contract')]);
    $ledger->appendMany([MovementIntent::of($this->sugar, $this->store, '20.0000', MovementReason::PurchaseIn, 'contract')]);
    $ledger->appendMany([MovementIntent::of($this->sugar, $this->store, '-1.2500', MovementReason::CountAdjustment, 'contract')]);

    $this->oracle = fn (string $connection): array => app(LedgerOracle::class)->check($connection)->toArray();
    $this->pair = fn (string $source, int $item, int $location, array $movements, array $recorded): array => [
        'source' => $source,
        'branch_id' => $this->branch->id,
        'item_id' => $item,
        'location_id' => $location,
        'movements' => ['on_hand' => $movements[0], 'reserved' => $movements[1]],
        'recorded' => ['on_hand' => $recorded[0], 'reserved' => $recorded[1]],
    ];
});

it('is ok on a seeded ledger, on a copy and on the original, and counts the items and places it read', function () {
    $scratch = ScratchCopy::ofDefault();

    expect(($this->oracle)($scratch))->toBe(['ok' => true, 'items' => 2, 'locations' => 2, 'mismatches' => []])
        ->and(($this->oracle)((string) config('database.default')))->toBe(['ok' => true, 'items' => 2, 'locations' => 2, 'mismatches' => []]);
});

it('names the item and place of a movement deleted behind the triggers\' back, with both figures, on the named connection only', function () {
    $scratch = ScratchCopy::ofDefault();
    $waste = (int) DB::table('stock_movements')->where('reason', 'waste')->value('id');
    ScratchCopy::withoutTriggers('stock_movements', fn (Connection $db) => $db->statement('DELETE FROM stock_movements WHERE id = ?', [$waste]));

    expect(($this->oracle)($scratch))->toBe([
        'ok' => false,
        'items' => 2,
        'locations' => 2,
        // the balance still holds the 2.5 the deleted row took out; the view is the movements' own sum, so it agrees with them
        'mismatches' => [($this->pair)('stock_balances', $this->flour, $this->store, ['60.0000', '0.0000'], ['57.5000', '0.0000'])],
    ])
        ->and(($this->oracle)((string) config('database.default'))['ok'])->toBeTrue('the original keeps its row')
        ->and(DB::table('stock_movements')->where('id', $waste)->exists())->toBeTrue();
});

it('reports a stock_balances row that disagrees with the movements as a pair of its own, beside a deleted movement', function () {
    $scratch = ScratchCopy::ofDefault();
    $waste = (int) DB::table('stock_movements')->where('reason', 'waste')->value('id');
    ScratchCopy::withoutTriggers('stock_movements', fn (Connection $db) => $db->statement('DELETE FROM stock_movements WHERE id = ?', [$waste]));
    ScratchCopy::withoutTriggers('stock_balances', fn (Connection $db) => $db->statement(
        'UPDATE stock_balances SET on_hand = ? WHERE item_id = ? AND location_id = ?',
        ['19.0000', $this->sugar, $this->store],
    ));

    $result = ($this->oracle)($scratch);
    expect($result['ok'])->toBeFalse()
        ->and($result['mismatches'])->toBe([
            ($this->pair)('stock_balances', $this->flour, $this->store, ['60.0000', '0.0000'], ['57.5000', '0.0000']),
            ($this->pair)('stock_balances', $this->sugar, $this->store, ['18.7500', '0.0000'], ['19.0000', '0.0000']),
        ]);
});

it('reports a view that no longer adds the movements up the ledger\'s way under item_stock_view, apart from the balances', function () {
    $scratch = ScratchCopy::ofDefault();
    $place = 'CASE WHEN from_location_id IS NULL THEN to_location_id WHEN to_location_id IS NULL THEN from_location_id '
        .'WHEN qty < 0 THEN from_location_id ELSE to_location_id END';
    // a view restored without its reservation rule: on hand still right, reserved always zero
    $select = "SELECT branch_id, item_id, {$place} AS location_id, "
        ."CAST(SUM(CASE WHEN reason IN ('reservation', 'reservation_release') THEN 0 ELSE qty END) AS DECIMAL(14,4)) AS on_hand, "
        .'CAST(0 AS DECIMAL(14,4)) AS reserved, '
        ."CAST(SUM(CASE WHEN reason IN ('reservation', 'reservation_release') THEN 0 ELSE qty END) AS DECIMAL(14,4)) AS available "
        ."FROM stock_movements GROUP BY branch_id, item_id, {$place}";

    $result = ScratchCopy::replacingView('item_stock_view', $select, fn (): array => ($this->oracle)($scratch));

    expect($result['ok'])->toBeFalse()
        ->and($result['mismatches'])->toBe([
            ($this->pair)('item_stock_view', $this->flour, $this->kitchen, ['34.0000', '4.0000'], ['34.0000', '0.0000']),
        ])
        ->and(($this->oracle)($scratch)['ok'])->toBeTrue('the migrated view is back');
});

it('reads only the named connection and writes nothing: every query it runs is a SELECT on that connection', function () {
    $scratch = ScratchCopy::ofDefault();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = [$query->connectionName, $query->sql];
    });

    app(LedgerOracle::class)->check($scratch);

    expect($queries)->not->toBeEmpty();
    foreach ($queries as [$connection, $sql]) {
        expect($connection)->toBe($scratch, "a query ran on {$connection}: {$sql}")
            ->and(strtolower(ltrim($sql)))->toStartWith('select');
    }
});

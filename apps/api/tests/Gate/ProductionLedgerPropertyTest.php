<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Models\Branch;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\ProductionFixture;

/*
 * Phase 2 gate, property criterion (phase-2 plan section 7, ProductionLedgerPropertyTest): random sequences of at most 50
 * operations (request, accept, start, done, confirm, send back, cancel, restock, spoilage, and an order's reservation and its
 * release) over a small menu with shared ingredients (two cakes at the cake room that both take flour; one of them with
 * waste on its cocoa), driven through the HTTP endpoints, with the cake room needing an overseer in half of the seeds. After
 * every operation:
 *   - the ledger oracle agrees: sum(movements) == stock_balances == item_stock_view;
 *   - `reserved` equals the reservations that are still held, and `available` (on_hand - reserved) is never below zero;
 *   - a refused Done or Confirm (409 ingredient_short) wrote no movement and left the batch where it was.
 * At the end, for every confirmed batch the production_consume rows are exactly the recipe at the recorded version scaled to
 * produced plus waste (computed here from the recipe rows with BigDecimal, half-up to 4 dp once), at the consume place, and
 * there is exactly one production_output of `produced` at the output place (none when nothing was produced).
 * 30 seeds; PRODUCTION_LEDGER_PROPERTY_SEED=<n> replays one. The seed and the last operations are in every failure message.
 */

uses(RefreshDatabase::class);

/** @return array<string, array{0: int}> */
function productionLedgerSeeds(): array
{
    $one = getenv('PRODUCTION_LEDGER_PROPERTY_SEED');
    $seeds = is_string($one) && ctype_digit($one) ? [(int) $one] : array_map(fn (int $i): int => 9203 + $i * 7919, range(1, 30));

    return array_combine(array_map(fn (int $s): string => "seed {$s}", $seeds), array_map(fn (int $s): array => [$s], $seeds));
}

it('keeps the ledger honest through random request and batch sequences: oracle, reserved, available, and the confirmed batches\' exact movements', function (int $seed) {
    $rng = new Randomizer(new Mt19937($seed));
    seedRoles();
    $branch = actInBranch(Branch::factory()->create());
    $b = $branch->id;
    $f = ProductionFixture::build($branch);
    $f->requireOverseer($rng->getInt(0, 1) === 1);
    $cocoa = Item::factory()->ingredient('kg')->create(['branch_id' => $b, 'created_by_user_id' => $f->admin->id, 'name_en' => 'Cocoa']);
    $cake2 = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create(['branch_id' => $b, 'created_by_user_id' => $f->admin->id, 'production_station_id' => $f->cakeRoomStation->id, 'name_en' => 'Chocolate cake']);
    $recipe2 = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $cake2->id, 'version' => 1, 'is_active' => true, 'yield_qty' => '7', 'yield_unit_id' => catalogUnit('pcs')->id]);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe2->id, 'ingredient_item_id' => $f->flour->id, 'qty' => '0.9', 'unit_id' => catalogUnit('kg')->id]);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe2->id, 'ingredient_item_id' => $cocoa->id, 'qty' => '0.3', 'unit_id' => catalogUnit('kg')->id, 'waste_pct' => '10.00']);
    $products = [$f->cake, $cake2];
    $ingredients = [$f->flour, $f->sugar, $cocoa];

    foreach ([[$f->flour, 6000], [$f->sugar, 4000], [$cocoa, 3000]] as [$item, $max]) {
        $has = $rng->getInt(0, $max);
        if ($has > 0) {
            stockIn($item, $f->cakeRoom, (string) $has);
        }
    }

    $http = fn (string $method, string $uri, array $body = []) => test()->withHeaders(idempotencyKey())->json($method, $uri, $body);
    $held = []; // reservation ref => [item id, qty]
    $ops = $rng->getInt(1, 50);
    $trace = [];
    for ($op = 1; $op <= $ops; $op++) {
        $at = "seed {$seed}, op {$op} (after: ".implode(', ', array_slice($trace, -4)).')';
        $requests = ProductionRequest::query()->orderBy('id')->get();
        $batches = ProductionBatch::query()->orderBy('id')->get()->keyBy('request_id');
        $busy = $requests->whereIn('status', array_map(fn (RequestStatus $s): string => $s->value, RequestStatus::open()))->pluck('product_item_id')->all();

        $candidates = [['restock'], ['restock'], ['spoil'], ['reserve'], ['request'], ['request']];
        foreach ($requests as $request) {
            match ($request->status) {
                RequestStatus::Requested => array_push($candidates, ['accept', $request->id], ['cancel', $request->id]),
                RequestStatus::Accepted => array_push($candidates, ['start', $request->id], ['start', $request->id], ['cancel', $request->id]),
                RequestStatus::InProgress => array_push($candidates, ['done', $request->id], ['done', $request->id]),
                RequestStatus::PendingConfirmation => array_push($candidates, ['confirm', $request->id], ['confirm', $request->id], ['send_back', $request->id]),
                default => null,
            };
        }
        if ($held !== []) {
            $candidates[] = ['release'];
        }
        [$kind, $id] = $candidates[$rng->getInt(0, count($candidates) - 1)] + [1 => null];
        $trace[] = $id === null ? $kind : "{$kind} #{$id}";
        $movementsBefore = DB::table('stock_movements')->where('branch_id', $b)->count();
        $batch = $id === null ? null : ($batches[$id] ?? null);
        $stateBefore = $batch === null ? null : $batch->status;
        $expect409 = false;

        try {
            switch ($kind) {
                case 'request':
                    $free = array_values(array_filter($products, fn (Item $p): bool => ! in_array($p->id, $busy, true)));
                    if ($free === []) {
                        break;
                    }
                    loginAs($f->cashier);
                    $http('POST', '/api/v1/production/requests', ['client_uuid' => (string) Str::uuid(), 'product_item_id' => $free[$rng->getInt(0, count($free) - 1)]->id, 'qty' => (string) $rng->getInt(1, 14)])->assertCreated();
                    break;
                case 'accept':
                    loginAs($f->baker);
                    $http('POST', "/api/v1/production/requests/{$id}/accept")->assertOk();
                    break;
                case 'cancel':
                    loginAs($f->manager);
                    $http('POST', "/api/v1/production/requests/{$id}/cancel", ['reason' => 'Property sequence'])->assertOk();
                    break;
                case 'start':
                    loginAs($f->baker);
                    $http('POST', "/api/v1/production/requests/{$id}/start", ['client_uuid' => (string) Str::uuid()])->assertOk();
                    break;
                case 'done':
                    loginAs($f->baker);
                    $produced = $rng->getInt(0, 14);
                    $waste = $rng->getInt($produced === 0 ? 1 : 0, 4);
                    $response = $http('POST', "/api/v1/production/batches/{$batch->id}/done", ['produced_qty' => (string) $produced, 'waste_qty' => (string) $waste]);
                    $expect409 = $response->getStatusCode() === 409;
                    expect($response->getStatusCode())->toBeIn([200, 409], "{$at}: done answered ".$response->getContent());
                    if ($expect409) {
                        expect($response->json('message_key'))->toBe('production.batch.ingredient_short', "{$at}: ".$response->getContent());
                    }
                    break;
                case 'confirm':
                    loginAs($f->manager);
                    $response = $http('POST', "/api/v1/production/batches/{$batch->id}/confirm");
                    $expect409 = $response->getStatusCode() === 409;
                    expect($response->getStatusCode())->toBeIn([200, 409], "{$at}: confirm answered ".$response->getContent());
                    if ($expect409) {
                        expect($response->json('message_key'))->toBe('production.batch.ingredient_short', "{$at}: ".$response->getContent());
                    }
                    break;
                case 'send_back':
                    loginAs($f->manager);
                    $http('POST', "/api/v1/production/batches/{$batch->id}/send-back", ['note' => 'Property sequence'])->assertOk();
                    break;
                case 'restock':
                    stockIn($ingredients[$rng->getInt(0, 2)], $f->cakeRoom, (string) $rng->getInt(100, 3000));
                    break;
                case 'spoil':
                    $item = $ingredients[$rng->getInt(0, 2)];
                    $level = app(\App\Modules\Inventory\Contracts\Stock::class)->of($item, $f->cakeRoom)->available();
                    $take = BigDecimal::of((string) $rng->getInt(1, 800));
                    if ($level->toBigDecimal()->isGreaterThanOrEqualTo($take)) {
                        stockIn($item, $f->cakeRoom, '-'.$take, MovementReason::Waste);
                    }
                    break;
                case 'reserve':
                    $item = $ingredients[$rng->getInt(0, 2)];
                    $qty = (string) $rng->getInt(50, 1500);
                    $ref = (string) Str::uuid();
                    withActor($f->cashier->id);
                    app(Reservations::class)->reserve([new ReserveLine($item->id, $qty, $f->cakeRoom->id)], $ref); // when short it takes nothing
                    $held[$ref] = $item->id;
                    break;
                case 'release':
                    $ref = array_keys($held)[$rng->getInt(0, count($held) - 1)];
                    withActor($f->cashier->id);
                    app(Reservations::class)->release($ref);
                    unset($held[$ref]);
                    break;
            }
        } catch (Throwable $e) {
            throw new RuntimeException("{$at}: {$kind} threw ".$e::class.': '.$e->getMessage(), 0, $e);
        }

        if ($expect409) {
            expect(DB::table('stock_movements')->where('branch_id', $b)->count())->toBe($movementsBefore, "{$at}: a refused write added movements")
                ->and(ProductionBatch::query()->findOrFail($batch->id)->status)->toBe($stateBefore, "{$at}: a refused write moved the batch");
        }

        $oracle = ledgerOracle($b);
        expect($oracle['balances'])->toBe($oracle['movements'], "{$at}: stock_balances != sum(movements)")
            ->and($oracle['view'])->toBe($oracle['movements'], "{$at}: item_stock_view != sum(movements)");

        $reservedNow = [];
        foreach (DB::table('stock_balances')->where('branch_id', $b)->get() as $row) {
            $onHand = BigDecimal::of((string) $row->on_hand);
            $reserved = BigDecimal::of((string) $row->reserved);
            expect($reserved->isNegative())->toBeFalse("{$at}: reserved < 0 at {$row->item_id}:{$row->location_id}")
                ->and($onHand->minus($reserved)->isNegative())->toBeFalse("{$at}: available < 0 at {$row->item_id}:{$row->location_id}");
            if (! $reserved->isZero()) {
                $reservedNow[$row->item_id] = (string) $reserved->toScale(4);
            }
        }
        $expectedReserved = [];
        foreach (DB::table('stock_movements')->where('branch_id', $b)->whereIn('reason', ['reservation', 'reservation_release'])->get() as $row) {
            $expectedReserved[$row->item_id] = ($expectedReserved[$row->item_id] ?? BigDecimal::zero())->plus((string) $row->qty);
        }
        $expectedReserved = array_filter(array_map(fn (BigDecimal $q): string => (string) $q->toScale(4), $expectedReserved), fn (string $q): bool => $q !== '0.0000');
        ksort($expectedReserved);
        ksort($reservedNow);
        expect($reservedNow)->toBe($expectedReserved, "{$at}: reserved != the reservation rows still held");
    }

    // every confirmed batch: exactly the recipe at the recorded version, scaled to produced plus waste, and one output
    foreach (ProductionBatch::query()->where('status', BatchStatus::Confirmed->value)->get() as $batch) {
        $request = ProductionRequest::query()->findOrFail($batch->request_id);
        $recipe = Recipe::query()->findOrFail($batch->recipe_id);
        $target = $batch->produced_qty->plus($batch->waste_qty)->toBigDecimal();
        $expected = [];
        foreach (RecipeLine::query()->where('recipe_id', $recipe->id)->where('is_optional', false)->get() as $line) {
            $grams = BigDecimal::of((string) $line->qty)->multipliedBy(1000); // the recipes of this test are written in kilograms
            $exact = $grams->multipliedBy(BigDecimal::of('100')->plus((string) $line->waste_pct))->multipliedBy($target)
                ->dividedBy(BigDecimal::of('100')->multipliedBy(BigDecimal::of((string) $recipe->yield_qty)), 4, RoundingMode::HalfUp);
            $expected[$line->ingredient_item_id] = (string) $exact->negated()->toScale(4);
        }
        $rows = DB::table('stock_movements')->where('ref_type', 'production_batch')->where('ref_id', $batch->id)->orderBy('id')->get();
        $consumed = $rows->where('reason', 'production_consume');
        $made = $rows->where('reason', 'production_output');
        $expected = array_filter($expected, fn (string $q): bool => $q !== '0.0000');
        ksort($expected);
        $actual = $consumed->mapWithKeys(fn ($r): array => [$r->item_id => (string) $r->qty])->all();
        ksort($actual);
        expect($actual)->toBe($expected, "seed {$seed}: batch #{$batch->id} consumed the recipe scaled to produced plus waste")
            ->and($consumed->pluck('from_location_id')->unique()->values()->all())->toBe($expected === [] ? [] : [$batch->consume_location_id])
            ->and($made->count())->toBe($batch->produced_qty->isZero() ? 0 : 1, "seed {$seed}: batch #{$batch->id} output rows");
        if (! $batch->produced_qty->isZero()) {
            expect($made->first()->item_id)->toBe($request->product_item_id)
                ->and((string) $made->first()->qty)->toBe($batch->produced_qty->toString())
                ->and($made->first()->to_location_id)->toBe($batch->output_location_id)
                ->and($batch->output_movement_id)->toBe($made->first()->id);
        }
        expect($rows->count())->toBe($consumed->count() + $made->count());
    }
    // a batch that is not confirmed wrote nothing
    foreach (ProductionBatch::query()->where('status', '!=', BatchStatus::Confirmed->value)->get() as $batch) {
        expect(DB::table('stock_movements')->where('ref_type', 'production_batch')->where('ref_id', $batch->id)->count())->toBe(0, "seed {$seed}: unconfirmed batch #{$batch->id} wrote movements");
    }
})->with(productionLedgerSeeds())->group('gate:p2', 'property', 'module:production');

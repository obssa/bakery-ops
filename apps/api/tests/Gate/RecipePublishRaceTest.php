<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;

/*
 * Step 2.4a, group concurrency (phase-2 plan sections 6 item 4, 7 and 10.3), through the whole HTTP stack in two processes
 * on two connections. A publish locks the product's item row, then swaps the active version; the unique key on
 * (product, active) is the backstop behind that lock. So:
 *   - the same recipe published twice at once: one new version (201) and one 409 catalog.recipe.unchanged, exactly two
 *     versions and one active in the end, one audit row;
 *   - two different recipes published at once: both are real changes, so they queue behind the lock and become versions 2
 *     and 3 in some order, never two with the same number and never two active;
 *   - two products published at once do not wait for each other and never deadlock (the engine's counter does not move).
 * CommitsForConcurrency (DatabaseTruncation): the children must see committed rows.
 */

uses(CommitsForConcurrency::class);

const RECIPE_RACE_ROUNDS = 30;

function recipeRaceDeadlocks(): int
{
    if (DB::getDriverName() === 'pgsql') {
        return (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
    }
    $row = DB::selectOne("show global status like 'Innodb_deadlocks'");

    return $row === null ? 0 : (int) $row->Value;
}

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    loginAs($this->admin);
    config(['cache.default' => 'database']); // the idempotency lock must be shared between the processes, as on the live host
    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->admin->id]);

    // a product with its version 1 (flour 500 g per piece)
    $this->makeProduct = function (): Item {
        $product = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->admin->id]);
        $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$product->id}/recipe", ($this->specWith)('500'))->assertCreated();

        return $product;
    };
    $this->specWith = fn (string $grams): array => [
        'yield_qty' => '1', 'yield_unit_id' => catalogUnit('pcs')->id,
        'lines' => [['ingredient_item_id' => $this->flour->id, 'qty' => $grams, 'unit_id' => catalogUnit('g')->id]],
    ];
    // a job for Forks::together: publish $grams of flour for $product as the admin and report what came back
    $this->publishJob = fn (int $productId, string $grams): Closure => function () use ($productId, $grams): array {
        DB::statement(DB::getDriverName() === 'pgsql' ? "set lock_timeout = '10s'" : 'set session innodb_lock_wait_timeout = 10');
        loginAs($this->admin);
        $response = $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$productId}/recipe/versions", ($this->specWith)($grams));

        return ['status' => $response->getStatusCode(), 'message_key' => $response->json('message_key'), 'version' => $response->json('version')];
    };
    $this->versionsOf = fn (int $productId): array => Recipe::query()->where('product_item_id', $productId)->orderBy('version')->pluck('is_active', 'version')->map(fn ($a) => (bool) $a)->all();
});

it('publishes the same recipe twice at once as one new version and one 409 catalog.recipe.unchanged (30 rounds)', function () {
    $deadlocksBefore = recipeRaceDeadlocks();
    $failures = [];
    foreach (range(1, RECIPE_RACE_ROUNDS) as $round) {
        $product = ($this->makeProduct)();

        $results = Forks::together([($this->publishJob)($product->id, '600'), ($this->publishJob)($product->id, '600')]);

        $statuses = collect($results)->map(fn ($r) => ($r['ok'] ?? false) ? $r['value']['status'] : -1)->sort()->values()->all();
        if ($statuses !== [201, 409]) {
            $failures[] = "round {$round}: ".json_encode($results);

            continue;
        }
        $loser = collect($results)->first(fn ($r) => $r['value']['status'] === 409);
        if (($loser['value']['message_key'] ?? null) !== 'catalog.recipe.unchanged') {
            $failures[] = "round {$round}: the loser answered ".json_encode($loser);
        }
        $versions = ($this->versionsOf)($product->id);
        $audits = DB::table('audit_log')->where('action', 'recipe.publish')->where('subject_type', (new Recipe)->getMorphClass())
            ->whereIn('subject_id', Recipe::query()->where('product_item_id', $product->id)->select('id'))->count();
        if ($versions !== [1 => false, 2 => true] || $audits !== 1) {
            $failures[] = "round {$round}: versions ".json_encode($versions).", {$audits} audit rows";
        }
    }

    expect($failures)->toBe([])->and(recipeRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:catalog', 'feature');

it('queues two different recipes published at once as versions 2 and 3, one active (30 rounds)', function () {
    $failures = [];
    foreach (range(1, RECIPE_RACE_ROUNDS) as $round) {
        $product = ($this->makeProduct)();

        $results = Forks::together([($this->publishJob)($product->id, '600'), ($this->publishJob)($product->id, '700')]);

        $answers = collect($results)->map(fn ($r) => ($r['ok'] ?? false) ? $r['value']['status'].':'.$r['value']['version'] : 'failed')->sort()->values()->all();
        if ($answers !== ['201:2', '201:3']) {
            $failures[] = "round {$round}: ".json_encode($results);

            continue;
        }
        if (($this->versionsOf)($product->id) !== [1 => false, 2 => false, 3 => true]) {
            $failures[] = "round {$round}: versions ".json_encode(($this->versionsOf)($product->id));
        }
    }

    expect($failures)->toBe([]);
})->group('concurrency', 'module:catalog', 'feature');

it('does not deadlock two products published at once (30 rounds)', function () {
    $deadlocksBefore = recipeRaceDeadlocks();
    $failures = [];
    foreach (range(1, RECIPE_RACE_ROUNDS) as $round) {
        $a = ($this->makeProduct)();
        $b = ($this->makeProduct)();

        $results = Forks::together([($this->publishJob)($a->id, '600'), ($this->publishJob)($b->id, '700')]);

        foreach ($results as $i => $r) {
            if (($r['ok'] ?? false) !== true || ($r['value']['status'] ?? null) !== 201 || ($r['value']['version'] ?? null) !== 2) {
                $failures[] = "round {$round}, process {$i}: ".json_encode($r);
            }
        }
        foreach ([$a, $b] as $product) {
            if (($this->versionsOf)($product->id) !== [1 => false, 2 => true]) {
                $failures[] = "round {$round}: versions ".json_encode(($this->versionsOf)($product->id));
            }
        }
    }

    expect($failures)->toBe([])->and(recipeRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:catalog', 'feature');

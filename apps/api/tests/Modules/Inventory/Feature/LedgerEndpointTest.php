<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;

/*
 * GET /ledger?item=&location=&from=&to= (inventory.view): the movements as written, newest first, paginated, with the
 * signed quantity in base units and its effect on on_hand and reserved as decimal strings. A location filter keeps the
 * rows that changed that location's balance (one side of a transfer each), so they add up to its balance; from/to
 * bound created_at inclusively (a bare date is a whole day in the branch's own calendar). Another branch's movements
 * never show.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->other = Branch::factory()->create();
    $this->admin = loginAs(makeUser($this->branch, 'admin'));
    actInBranch($this->branch);
    withActor($this->admin->id);
    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    $this->milk = Item::factory()->ingredient('L')->create(['branch_id' => $this->branch->id]);
    $this->store = stockLocation($this->branch);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen);

    $this->t0 = CarbonImmutable::parse('2026-10-19 07:00:00', 'UTC');
    $this->travelTo($this->t0);
    stockIn($this->flour, $this->store, '5000');
    stockIn($this->milk, $this->store, '3000');
    $this->travelTo($this->t0->addHour());
    app(Ledger::class)->appendMany(MovementIntent::transfer($this->flour->id, $this->store->id, $this->kitchen->id, '1000', 'transfer'));
    $this->travelTo($this->t0->addHours(2));
    stockIn($this->flour, $this->kitchen, '-100', MovementReason::Waste);
});

it('lists the movements of the branch newest first, with signed base quantities and their effect', function () {
    $response = $this->getJson('/api/v1/ledger')->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(5)
        ->and($data[0]['reason'])->toBe('waste')
        ->and($data[0]['qty'])->toBe('-100.0000')
        ->and($data[0]['on_hand_delta'])->toBe('-100.0000')
        ->and($data[0]['reserved_delta'])->toBe('0.0000')
        ->and($data[0]['location_id'])->toBe($this->kitchen->id)
        ->and($data[0]['user_id'])->toBe($this->admin->id)
        ->and($data[0]['created_at'])->toBe($this->t0->addHours(2)->toIso8601String())
        ->and($response->json('meta.total'))->toBe(5);
})->group('module:inventory', 'feature');

it('filters by item, by location (both sides of a transfer) and by an inclusive time window', function () {
    $ids = fn (string $query): array => array_column($this->getJson('/api/v1/ledger?'.$query)->assertOk()->json('data'), 'reason');

    expect($ids("item={$this->milk->id}"))->toBe(['purchase_in'])
        ->and($ids("item={$this->flour->id}&location={$this->kitchen->id}"))->toBe(['waste', 'transfer'])
        ->and($ids("location={$this->store->id}"))->toBe(['transfer', 'purchase_in', 'purchase_in']);

    $from = urlencode($this->t0->addHour()->toIso8601String());
    $to = urlencode($this->t0->addHour()->toIso8601String());
    expect($ids("from={$from}&to={$to}"))->toBe(['transfer', 'transfer'])
        ->and($ids('from='.urlencode($this->t0->addHours(2)->toIso8601String())))->toBe(['waste'])
        ->and($ids('to='.urlencode($this->t0->toIso8601String())))->toBe(['purchase_in', 'purchase_in']);

    $page = $this->getJson('/api/v1/ledger?per_page=2')->assertOk();
    expect($page->json('data'))->toHaveCount(2)->and($page->json('meta.last_page'))->toBe(3);
})->group('module:inventory', 'feature');

it('validates the filters and keeps other branches out', function () {
    $theirFlour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->other->id]);
    $theirStore = stockLocation($this->other);

    $this->getJson("/api/v1/ledger?item={$theirFlour->id}")->assertStatus(422)->assertJsonValidationErrors(['item']);
    $this->getJson("/api/v1/ledger?location={$theirStore->id}")->assertStatus(422)->assertJsonValidationErrors(['location']);
    $this->getJson('/api/v1/ledger?from=2026-10-20&to=2026-10-19')->assertStatus(422)->assertJsonValidationErrors(['to']);
    $this->getJson('/api/v1/ledger?per_page=1000')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    $this->getJson('/api/v1/ledger?from=yesterday-ish')->assertStatus(422)->assertJsonValidationErrors(['from']);

    // movements written in another branch never show up here
    $theirAdmin = makeUser($this->other, 'admin');
    actInBranch($this->other);
    withActor($theirAdmin->id);
    stockIn($theirFlour, $theirStore, '1');
    actInBranch($this->branch);
    withActor($this->admin->id);
    expect($this->getJson('/api/v1/ledger')->json('meta.total'))->toBe(5);
})->group('module:inventory', 'feature');

it('lets every floor role read the ledger and refuses the waiter', function () {
    foreach (['manager', 'cashier', 'chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson('/api/v1/ledger')->assertOk();
    }
    loginAs(makeUser($this->branch, 'waiter'));
    $this->getJson('/api/v1/ledger')->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
})->group('module:inventory', 'feature');

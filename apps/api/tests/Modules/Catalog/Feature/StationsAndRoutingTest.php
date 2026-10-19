<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Contracts\Routing;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemStationAssignment;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;

/*
 * Where a line goes and who makes a product (dependency-graph.md, "Two Catalog facts"): item_station_assignments
 * route order lines to a KDS (latte -> bar); a display-case product has none, so Routing::stationFor is null, while
 * items.production_station_id names the station that makes it (cake -> cake_room) for Production's requests.
 * PUT /items/{item}/stations replaces the assignments as one audited write; removed rows are soft-deleted and
 * restored when they come back (rule 4).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = loginAs(makeUser($this->branch, 'admin'));
    actInBranch($this->branch);
    withActor($this->admin->id);
    $this->kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id]);
    $this->bar = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $this->branch->id]);
    $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $this->branch->id]);
    $this->routing = app(Routing::class);
});

it('routes a latte to the bar once assigned, and replaces assignments soft-deleting and restoring rows', function () {
    $latte = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);
    expect($this->routing->stationFor($latte))->toBeNull();

    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$latte->id}/stations", ['station_ids' => [$this->bar->id]])
        ->assertOk()
        ->assertJsonPath('item_id', $latte->id)
        ->assertJsonPath('station_ids', [$this->bar->id]);
    expect($this->routing->stationFor($latte)?->id)->toBe($this->bar->id);

    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$latte->id}/stations", ['station_ids' => [$this->kitchen->id]])
        ->assertOk()->assertJsonPath('station_ids', [$this->kitchen->id]);
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$latte->id}/stations", ['station_ids' => [$this->bar->id, $this->kitchen->id]])
        ->assertOk()->assertJsonPath('station_ids', [$this->kitchen->id, $this->bar->id]);

    expect(ItemStationAssignment::query()->where('item_id', $latte->id)->count())->toBe(2)
        ->and(ItemStationAssignment::withTrashed()->where('item_id', $latte->id)->count())->toBe(2)
        ->and($this->routing->stationFor($latte)?->id)->toBe($this->kitchen->id)
        ->and(collect($this->routing->stationsFor($latte))->pluck('id')->all())->toBe([$this->kitchen->id, $this->bar->id])
        ->and(AuditLog::query()->where('action', 'item.stations.replace')->where('subject_id', $latte->id)->count())->toBe(3);

    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$latte->id}/stations", ['station_ids' => []])
        ->assertOk()->assertJsonPath('station_ids', []);
    expect($this->routing->stationFor($latte))->toBeNull();
})->group('module:catalog', 'feature');

it('has no KDS station for a display-case cake but names the cake room as the station that makes it', function () {
    $cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id, 'production_station_id' => $this->cakeRoom->id]);

    expect($this->routing->stationFor($cake))->toBeNull()
        ->and($this->routing->productionStationFor($cake)?->id)->toBe($this->cakeRoom->id)
        ->and($this->routing->productionStationFor($cake)?->code)->toBe(StationCode::CakeRoom);
})->group('module:catalog', 'feature');

it('makes a to-order product where it is routed, and a bought-in product nowhere', function () {
    $sandwich = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);
    $sandwich->stationAssignments()->create(['branch_id' => $this->branch->id, 'station_id' => $this->kitchen->id]);
    $water = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $this->branch->id]);
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);

    expect($this->routing->productionStationFor($sandwich)?->id)->toBe($this->kitchen->id)
        ->and($this->routing->stationFor($water))->toBeNull()
        ->and($this->routing->productionStationFor($water))->toBeNull()
        ->and($this->routing->stationFor($flour))->toBeNull()
        ->and($this->routing->productionStationFor($flour))->toBeNull();
})->group('module:catalog', 'feature');

it('refuses stations of another branch, an ingredient, and a malformed payload', function () {
    $latte = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);
    $foreign = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => Branch::factory()->create()->id]);
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);

    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$latte->id}/stations", ['station_ids' => [$foreign->id]])
        ->assertStatus(422)->assertJsonValidationErrors(['station_ids.0']);
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$latte->id}/stations", ['station_ids' => 'bar'])
        ->assertStatus(422)->assertJsonValidationErrors(['station_ids']);
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$latte->id}/stations", [])
        ->assertStatus(422)->assertJsonValidationErrors(['station_ids']);
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$flour->id}/stations", ['station_ids' => [$this->kitchen->id]])
        ->assertStatus(422)->assertJsonPath('message_key', 'catalog.item.not_a_product');

    expect(ItemStationAssignment::withTrashed()->count())->toBe(0);
})->group('module:catalog', 'feature');

it('refuses station assignment to every role but admin', function () {
    $latte = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);
    foreach (array_diff(identityRoles(), ['admin']) as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->withHeaders(idempotencyKey())->putJson("/api/v1/items/{$latte->id}/stations", ['station_ids' => [$this->bar->id]])->assertStatus(403);
    }
    expect(ItemStationAssignment::withTrashed()->count())->toBe(0);
})->group('module:catalog', 'feature');

it('replays a station assignment idempotently', function () {
    $latte = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);

    assertIdempotent($this, 'PUT', "/api/v1/items/{$latte->id}/stations", ['station_ids' => [$this->bar->id]], 'audit_log');
})->group('module:catalog', 'feature');

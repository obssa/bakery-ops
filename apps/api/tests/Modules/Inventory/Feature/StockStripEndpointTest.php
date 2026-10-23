<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\ReorderRule;
use App\Shared\Enums\StationCode;
use App\Shared\Live\OverlapCursor;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

pest()->group('module:inventory', 'feature');

/*
 * Step 2.6b (plan section 4, "GET /stations/{station}/stock-strip"): the KDS stock strip. The ingredients held at the station's stock
 * location with their available, the minimum of the rule for that place (or null) and a flag: out (available 0 or below), low (at or
 * below the minimum), ok. Out first, then low, then by available over minimum (items with no minimum last); `?limit=` (default 5, at
 * most 50). inventory.view and assigned to the station, or stations.all (403 inventory.station.forbidden otherwise). Polled by the KDS,
 * so it carries an ETag and answers 304 when nothing it shows has changed, in a fixed number of queries.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->other = Branch::factory()->create();
    $b = $this->branch->id;
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    $this->kitchenStation = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
    $this->barStation = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $b]);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen, $this->kitchenStation);
    $this->bar = stockLocation($this->branch, LocationType::Bar, $this->barStation);
    $this->chef = makeUser($this->branch, 'chef');
    UserStationAssignment::factory()->create(['branch_id' => $b, 'user_id' => $this->chef->id, 'station_id' => $this->kitchenStation->id]);
    loginAs($this->chef);
    $this->ingredient = fn (string $en, string $am, string $stock, ?string $min = null, bool $at = true): Item => tap(
        Item::factory()->ingredient('g')->create(['branch_id' => $b, 'name_en' => $en, 'name_am' => $am]),
        function (Item $item) use ($stock, $min, $b): void {
            stockIn($item, $this->kitchen, $stock === '0' ? '1' : $stock);
            if ($stock === '0') {
                stockIn($item, $this->kitchen, '-1', MovementReason::Waste);
            }
            if ($min !== null) {
                ReorderRule::factory()->create(['branch_id' => $b, 'item_id' => $item->id, 'location_id' => $this->kitchen->id, 'location_key' => $this->kitchen->id, 'min_qty' => $min, 'reorder_qty' => '100', 'notify_roles' => ['admin']]);
            }
        },
    );
    $this->strip = fn (?Station $station = null, string $query = '', array $headers = []) => $this->withHeaders($headers)
        ->getJson('/api/v1/stations/'.($station ?? $this->kitchenStation)->id.'/stock-strip'.$query);
});

it('shows the station location\'s ingredients, out first, then low, then by available over minimum, with no minimum last', function () {
    $flour = ($this->ingredient)('Flour', 'ዱቄት', '0', '500');
    $sugar = ($this->ingredient)('Sugar', 'ስኳር', '300', '500');
    $butter = ($this->ingredient)('Butter', 'ቅቤ', '400', '100');
    $yeast = ($this->ingredient)('Yeast', 'እርሾ', '900', '300');
    $salt = ($this->ingredient)('Salt', 'ጨው', '50');

    $response = ($this->strip)(null, '?limit=10')->assertOk();

    expect($response->json('location_id'))->toBe($this->kitchen->id)
        ->and(array_column($response->json('data'), 'state'))->toBe(['out', 'low', 'ok', 'ok', 'ok'])
        ->and(array_column(array_column($response->json('data'), 'item'), 'id'))->toBe([$flour->id, $sugar->id, $yeast->id, $butter->id, $salt->id]);
    expect($response->json('data.0'))->toBe([
        'item' => ['id' => $flour->id, 'name_en' => 'Flour', 'name_am' => 'ዱቄት'], 'unit' => 'g', 'available' => '0.0000', 'min_qty' => '500.0000', 'state' => 'out',
    ])->and($response->json('data.4'))->toBe([
        'item' => ['id' => $salt->id, 'name_en' => 'Salt', 'name_am' => 'ጨው'], 'unit' => 'g', 'available' => '50.0000', 'min_qty' => null, 'state' => 'ok',
    ]);
    expect(array_keys($response->json()))->toBe(['location_id', 'data']);
});

it('leaves a deleted or switched-off ingredient off the strip, so leftovers cannot crowd out the live ones', function () {
    $live = ($this->ingredient)('Live', 'ሕያው', '900', '300');
    $deleted = ($this->ingredient)('Deleted', 'የተሰረዘ', '0', '500');
    $off = ($this->ingredient)('Off', 'የጠፋ', '0', '500');
    $deleted->delete();
    $off->forceFill(['is_active' => false])->save();

    $ids = array_column(array_column(($this->strip)(null, '?limit=10')->assertOk()->json('data'), 'item'), 'id');

    expect($ids)->toBe([$live->id]);
});

it('counts an item at exactly its minimum as low', function () {
    ($this->ingredient)('Flour', 'ዱቄት', '500', '500');

    ($this->strip)()->assertJsonPath('data.0.state', 'low')->assertJsonPath('data.0.available', '500.0000');
});

it('keeps products and other places\' stock out of the strip', function () {
    ($this->ingredient)('Flour', 'ዱቄት', '10', '5');
    $cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);
    stockIn($cake, $this->kitchen, '4');
    $milk = Item::factory()->ingredient('ml')->create(['branch_id' => $this->branch->id]);
    stockIn($milk, $this->bar, '4000');

    $ids = array_column(array_column(($this->strip)()->assertOk()->json('data'), 'item'), 'id');

    expect($ids)->toHaveCount(1)->and($ids)->not->toContain($cake->id)->and($ids)->not->toContain($milk->id);
});

it('limits the list to five by default and to ?limit= at most 50', function () {
    foreach (range(1, 7) as $i) {
        ($this->ingredient)('Item '.$i, 'እቃ '.$i, (string) ($i * 10), '1000');
    }

    expect(($this->strip)()->assertOk()->json('data'))->toHaveCount(5)
        ->and(($this->strip)(null, '?limit=2')->assertOk()->json('data'))->toHaveCount(2)
        ->and(($this->strip)(null, '?limit=50')->assertOk()->json('data'))->toHaveCount(7);
    ($this->strip)(null, '?limit=0')->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    ($this->strip)(null, '?limit=51')->assertStatus(422);
});

it('answers an empty strip for a station with no stock location', function () {
    $pastry = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $this->branch->id]);
    loginAs($this->admin);

    ($this->strip)($pastry)->assertOk()->assertExactJson(['location_id' => null, 'data' => []]);
});

it('shows a station\'s strip to the people assigned to it and to stations.all, and refuses the rest with a bilingual 403', function () {
    ($this->ingredient)('Flour', 'ዱቄት', '10', '5');
    ($this->strip)()->assertOk();

    $response = ($this->strip)($this->barStation);
    $response->assertForbidden()->assertJsonPath('message_key', 'inventory.station.forbidden');
    expect($response->json('message_en'))->not->toBe('inventory.station.forbidden')->and($response->json('message_am'))->toMatch('/\p{Ethiopic}/u');

    loginAs($this->admin);
    ($this->strip)($this->barStation)->assertOk()->assertJsonPath('location_id', $this->bar->id);
    ($this->strip)($this->kitchenStation)->assertOk();
    loginAs(makeUser($this->branch, 'manager'));
    ($this->strip)($this->barStation)->assertOk();
});

it('refuses a person without inventory.view even when assigned to the station', function () {
    $waiter = makeUser($this->branch, 'waiter');
    UserStationAssignment::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $waiter->id, 'station_id' => $this->kitchenStation->id]);
    loginAs($waiter);

    ($this->strip)()->assertForbidden();
});

it('treats another branch\'s station as not found', function () {
    $foreign = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->other->id]);

    ($this->strip)($foreign)->assertNotFound();
    loginAs($this->admin);
    ($this->strip)($foreign)->assertNotFound();
});

it('stamps an ETag and Cache-Control, answers 304 with no body when nothing changed, and a new ETag after a movement', function () {
    $flour = ($this->ingredient)('Flour', 'ዱቄት', '100', '50');

    $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds(); // past the settling window of the ledger tag
    $first = ($this->strip)()->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
    $etag = $first->headers->get('ETag');
    expect($etag)->toBeString()->not->toBe('');

    $again = ($this->strip)(null, '', ['If-None-Match' => $etag]);
    $again->assertStatus(304);
    expect($again->getContent())->toBe('')->and($again->headers->get('ETag'))->toBe($etag);
    ($this->strip)(null, '', ['If-None-Match' => 'W/'.$etag])->assertStatus(304);

    stockIn($flour, $this->kitchen, '-70', MovementReason::Waste);
    $changed = ($this->strip)(null, '', ['If-None-Match' => $etag])->assertOk();
    expect($changed->headers->get('ETag'))->not->toBe($etag)
        ->and($changed->json('data.0.state'))->toBe('low');
});

it('gets a new ETag when a rule changes, is deleted, or the limit differs, and a different one per station', function () {
    $flour = ($this->ingredient)('Flour', 'ዱቄት', '100', '50');
    $etag = fn (?Station $s = null, string $q = ''): string => (string) ($this->strip)($s, $q)->assertOk()->headers->get('ETag');
    $base = $etag();

    ReorderRule::query()->where('item_id', $flour->id)->update(['min_qty' => '90', 'version' => 2, 'updated_at' => now()->addSeconds(5)]);
    $raised = $etag();
    expect($raised)->not->toBe($base);

    ReorderRule::query()->where('item_id', $flour->id)->firstOrFail()->delete();
    $deleted = $etag();
    expect($deleted)->not->toBe($raised)
        ->and($etag(null, '?limit=3'))->not->toBe($deleted);
    loginAs($this->admin);
    expect($etag($this->barStation))->not->toBe($deleted);
});

it('does the work in a fixed number of queries as the items grow, and almost none for a 304', function () {
    $count = function (array $headers = []): int {
        $n = 0;
        DB::listen(function (QueryExecuted $q) use (&$n): void {
            $n++;
        });
        ($this->strip)(null, '?limit=50', $headers);

        return $n;
    };
    foreach (range(1, 3) as $i) {
        ($this->ingredient)('A'.$i, 'አ'.$i, (string) ($i * 100), '150');
    }
    $count(); // the first request of a test also warms the permission and role caches
    $few = $count();
    foreach (range(4, 20) as $i) {
        ($this->ingredient)('A'.$i, 'አ'.$i, (string) ($i * 100), $i % 2 === 0 ? '500' : null);
    }
    $many = $count();
    $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds(); // past the settling window of the ledger tag
    $etag = ($this->strip)(null, '?limit=50')->headers->get('ETag');
    $notModified = $count(['If-None-Match' => (string) $etag]);

    expect($many)->toBe($few)->and($notModified)->toBeLessThan($many);
});

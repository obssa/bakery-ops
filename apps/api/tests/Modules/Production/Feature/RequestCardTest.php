<?php

declare(strict_types=1);

use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ProductionFixture;

pest()->group('module:production', 'feature');

/*
 * GET /production/requests/{request} (step 2.7b; phase-2 plan section 4, "The card"): the request resource plus what the baker
 * works from: the active recipe scaled to the requested quantity by Catalog (Recipes::scaled: every line in base units with
 * waste once, the steps in order, in both languages), and for each ingredient what is at the station's stock place (on hand,
 * available = on hand minus reserved), whether it is short and by how much, and, for a short one, what the store could give
 * (the hint for a transfer request). The two places a batch will use are named. Any floor role reads it; another branch's
 * request is 404.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    loginAs($this->f->baker);
    $this->card = fn (ProductionRequest $request) => $this->getJson('/api/v1/production/requests/'.$request->id);
});

it('scales the recipe to the requested quantity and shows each ingredient with what is at the cake room', function () {
    stockIn($this->f->flour, $this->f->cakeRoom, '1000');
    stockIn($this->f->sugar, $this->f->cakeRoom, '5000');
    stockIn($this->f->flour, $this->f->store, '10000');
    $request = $this->f->request();

    $response = ($this->card)($request)->assertOk();

    $response->assertJsonPath('id', $request->id)
        ->assertJsonPath('status', 'requested')
        ->assertJsonPath('product.name_en', 'Black forest cake')
        ->assertJsonPath('recipe.version', 1)
        ->assertJsonPath('recipe.yield', ['qty' => '12.0000', 'unit' => 'pcs'])
        ->assertJsonPath('recipe.target_qty', '12.0000')
        ->assertJsonPath('recipe.steps', [
            ['order' => 1, 'text_en' => 'Cream the butter', 'text_am' => 'ቅቤውን ይምቱ'],
            ['order' => 2, 'text_en' => 'Bake for 40 minutes', 'text_am' => 'ለ40 ደቂቃ ይጋግሩ'],
        ])
        ->assertJsonPath('consume_location', ['id' => $this->f->cakeRoom->id, 'name_en' => $this->f->cakeRoom->name_en, 'name_am' => $this->f->cakeRoom->name_am])
        ->assertJsonPath('output_location', ['id' => $this->f->display->id, 'name_en' => $this->f->display->name_en, 'name_am' => $this->f->display->name_am]);

    $lines = $response->json('recipe.lines');
    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toBe([
            'ingredient' => ['id' => $this->f->flour->id, 'name_en' => 'Flour', 'name_am' => 'ዱቄት', 'unit' => 'g'],
            'needed' => '1200.0000', 'on_hand' => '1000.0000', 'available' => '1000.0000',
            'short' => true, 'short_qty' => '200.0000',
            'transfer_hint' => ['from_location_id' => $this->f->store->id, 'store_available' => '10000.0000'],
        ])
        ->and($lines[1])->toBe([
            'ingredient' => ['id' => $this->f->sugar->id, 'name_en' => 'Sugar', 'name_am' => 'ስኳር', 'unit' => 'g'],
            'needed' => '600.0000', 'on_hand' => '5000.0000', 'available' => '5000.0000',
            'short' => false, 'short_qty' => '0.0000',
            'transfer_hint' => null,
        ]);
});

it('scales to the quantity asked for, not the yield', function () {
    stockIn($this->f->flour, $this->f->cakeRoom, '9000');
    $request = $this->f->request(['requested_qty' => '30']);

    $response = ($this->card)($request)->assertOk();

    expect($response->json('recipe.target_qty'))->toBe('30.0000')
        ->and($response->json('recipe.lines.0.needed'))->toBe('3000.0000')
        ->and($response->json('recipe.lines.1.needed'))->toBe('1500.0000');
});

it('counts only what is not held for orders as available', function () {
    stockIn($this->f->sugar, $this->f->cakeRoom, '5000');
    app(Reservations::class)->reserve([new ReserveLine($this->f->sugar->id, '4800', $this->f->cakeRoom->id)], (string) Str::uuid());
    $request = $this->f->request();

    $line = ($this->card)($request)->json('recipe.lines.1');

    expect($line['on_hand'])->toBe('5000.0000')
        ->and($line['available'])->toBe('200.0000')
        ->and($line['short'])->toBeTrue()
        ->and($line['short_qty'])->toBe('400.0000');
});

it('says nothing is there for an ingredient the place has never held, and gives no hint when there is no store', function () {
    $request = $this->f->request();
    DB::table('locations')->where('id', $this->f->store->id)->update(['deleted_at' => now()]);

    $line = ($this->card)($request)->json('recipe.lines.0');

    expect($line['on_hand'])->toBe('0.0000')->and($line['available'])->toBe('0.0000')
        ->and($line['short'])->toBeTrue()->and($line['short_qty'])->toBe('1200.0000')
        ->and($line['transfer_hint'])->toBeNull();
});

it('does not judge the stock when the station has no place of its own: the place is null and so are the numbers', function () {
    DB::table('locations')->where('id', $this->f->bakery->id)->update(['deleted_at' => now()]);
    $request = $this->f->request(['product_item_id' => $this->f->bread->id, 'station_id' => $this->f->bakeryStation->id, 'requested_qty' => '40']);

    $response = ($this->card)($request)->assertOk();

    expect($response->json('consume_location'))->toBeNull()
        ->and($response->json('recipe.lines.0.needed'))->toBe('10000.0000')
        ->and($response->json('recipe.lines.0.on_hand'))->toBeNull()
        ->and($response->json('recipe.lines.0.available'))->toBeNull()
        ->and($response->json('recipe.lines.0.short'))->toBeNull()
        ->and($response->json('recipe.lines.0.short_qty'))->toBeNull()
        ->and($response->json('recipe.lines.0.transfer_hint'))->toBeNull();
});

it('has no recipe on the card when the product no longer has an active one', function () {
    $request = $this->f->request();
    $this->f->cakeRecipe->forceFill(['is_active' => false])->save();

    ($this->card)($request)->assertOk()->assertJsonPath('recipe', null)->assertJsonPath('id', $request->id);
});

it('carries the batch of a request that has one', function () {
    $request = $this->f->request([], RequestStatus::InProgress);
    $batch = ProductionBatch::factory()->create(['branch_id' => $this->branch->id, 'request_id' => $request->id, 'station_id' => $request->station_id, 'recipe_id' => $this->f->cakeRecipe->id]);

    $response = ($this->card)($request)->assertOk();

    expect($response->json('status'))->toBe('in_progress')
        ->and($response->json('batch'))->toMatchArray([
            'id' => $batch->id, 'client_uuid' => $batch->client_uuid, 'status' => 'started', 'recipe_version' => 1, 'planned_qty' => '12.0000',
            'produced_qty' => null, 'waste_qty' => null, 'finished_by' => null, 'finished_at' => null, 'confirmed_by' => null, 'confirmed_at' => null,
            'send_back_note' => null, 'send_back_count' => 0, 'version' => 1,
        ])
        ->and($response->json('batch.started_at'))->not->toBeNull();
});

it('names the people of a finished batch', function () {
    $request = $this->f->request(['assigned_to_user_id' => $this->f->baker->id], RequestStatus::PendingConfirmation);
    ProductionBatch::factory()->finished()->create([
        'branch_id' => $this->branch->id, 'request_id' => $request->id, 'station_id' => $request->station_id, 'recipe_id' => $this->f->cakeRecipe->id,
        'started_by_user_id' => $this->f->baker->id, 'finished_by_user_id' => $this->f->baker->id, 'produced_qty' => '11', 'waste_qty' => '1',
    ]);

    $batch = ($this->card)($request)->json('batch');

    expect($batch['status'])->toBe('finished')
        ->and($batch['produced_qty'])->toBe('11.0000')->and($batch['waste_qty'])->toBe('1.0000')
        ->and($batch['finished_by'])->toBe(['id' => $this->f->baker->id, 'name' => $this->f->baker->name])
        ->and($batch['confirmed_by'])->toBeNull();
});

it('still names a requester who has since left', function () {
    $request = $this->f->request();
    $this->f->cashier->forceFill(['is_active' => false])->save();

    ($this->card)($request)->assertOk()
        ->assertJsonPath('requested_by', ['id' => $this->f->cashier->id, 'name' => $this->f->cashier->name]);
});

it('is read by every floor role, refused to a waiter, and is 404 for another branch, a missing id or a non-number', function () {
    $request = $this->f->request();
    foreach ([$this->f->cashier, $this->f->chef, $this->f->manager, $this->f->admin, $this->f->breadBaker] as $person) {
        loginAs($person);
        ($this->card)($request)->assertOk();
    }
    loginAs($this->f->waiter);
    ($this->card)($request)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');

    loginAs($this->f->baker);
    $theirs = ProductionRequest::factory()->create(['branch_id' => Branch::factory()->create()->id]);
    ($this->card)($theirs)->assertStatus(404)->assertJsonPath('message_key', 'http.not_found');
    $this->getJson('/api/v1/production/requests/999999')->assertStatus(404);
    $this->getJson('/api/v1/production/requests/abc')->assertStatus(404);
});

<?php

declare(strict_types=1);

use App\Shared\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShiftsFixture;

uses(RefreshDatabase::class);

pest()->group('gate:p3', 'module:shifts', 'feature');

/*
 * Gate criterion: "enforcement rejects a station-location movement without an open shift". Since step 3.7e the rule ships on, so this
 * runs on the defaults, through the HTTP layer and the Ledger: a movement at a station that tracks shifts is refused 409
 * inventory.shift.required until that station's shift is open, and refused again while the shift is counting (the freeze), while a place
 * of a station that tracks no shifts (the store) is never asked. The table of every reason at every place is LedgerEnforcementTest.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->kitchenPlace = $this->f->place($this->f->kitchen);
    $this->storePlace = $this->f->place($this->f->store);
    $this->item = $this->f->ingredient();
    $this->f->stock($this->item, $this->kitchenPlace, '5000');
    // the state the config files ship in (the fixture builds with the rules off so it can stock the place first)
    config(['ledger.require_shift_for_station_locations' => true, 'payments.require_shift_for_cash' => true, 'shifts.open_ad_hoc_on_accept' => true]);
    $this->waste = fn (string $uuid) => $this->f->send('POST', '/api/v1/stock/movements', $this->f->admin, [
        'item_id' => $this->item->id, 'location_id' => $this->kitchenPlace->id, 'reason' => 'waste', 'qty' => '100', 'client_uuid' => $uuid,
    ]);
});

it('ships the three rules on: the default of each key in the config files is true', function () {
    foreach ([['ledger', "env('LEDGER_REQUIRE_SHIFT_FOR_STATION_LOCATIONS', true)"], ['payments', "env('PAYMENTS_REQUIRE_SHIFT_FOR_CASH', true)"], ['shifts', "env('SHIFTS_OPEN_AD_HOC_ON_ACCEPT', true)"]] as [$file, $default]) {
        expect(file_get_contents(config_path("{$file}.php")))->toContain($default);
    }
});

it('refuses a movement at a station without an open shift, allows it in one, and refuses it again while the shift is counting', function () {
    $before = DB::table('stock_movements')->count();

    ($this->waste)('7e1c1f1e-0000-4000-8000-000000000001')
        ->assertStatus(409)->assertJsonPath('message_key', 'inventory.shift.required')
        ->assertJsonPath('station_id', $this->f->kitchen->id)->assertJsonPath('location_id', $this->kitchenPlace->id);
    expect(DB::table('stock_movements')->count())->toBe($before);

    $shiftId = (int) $this->f->start($this->f->chef, $this->f->kitchen, ['carry' => true])->assertSuccessful()->json('shift.id');
    ($this->waste)('7e1c1f1e-0000-4000-8000-000000000002')->assertCreated()->assertJsonPath('balance.on_hand', '4900.0000');
    expect(DB::table('stock_movements')->where('reason', 'waste')->value('shift_instance_id'))->toBe($shiftId);

    $this->f->close($this->f->chef, $shiftId)->assertSuccessful();
    ($this->waste)('7e1c1f1e-0000-4000-8000-000000000003')->assertStatus(409)->assertJsonPath('message_key', 'inventory.shift.required');
    expect(DB::table('stock_movements')->where('reason', 'waste')->count())->toBe(1);
});

it('never asks the store, which tracks no shifts, for one', function () {
    $this->f->send('POST', '/api/v1/stock/movements', $this->f->admin, [
        'item_id' => $this->item->id, 'location_id' => $this->storePlace->id, 'reason' => 'purchase_in', 'qty' => '10',
    ])->assertCreated();
});

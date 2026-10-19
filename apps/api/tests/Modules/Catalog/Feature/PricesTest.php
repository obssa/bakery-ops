<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Contracts\Prices;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemPrice;
use App\Shared\Models\Branch;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;

/*
 * item_prices is a history, never overwritten (domain-model.md): POST /items/{item}/prices closes the open price at
 * the new price's effective_from and opens the new one, in one audited write. Prices::at(item, at) answers with the
 * price effective then: effective_from inclusive, effective_to exclusive. Order lines snapshot it (Phase 1).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = loginAs(makeUser($this->branch, 'admin'));
    actInBranch($this->branch);
    withActor($this->admin->id);
    $this->item = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);
    $this->prices = app(Prices::class);
});

it('opens the first price now, in the branch currency, as a decimal string, stamped and audited', function () {
    $this->travelTo('2026-10-19 08:00:00');

    $response = $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$this->item->id}/prices", ['price' => '45.5']);

    $response->assertCreated()
        ->assertJsonPath('item_id', $this->item->id)
        ->assertJsonPath('price', '45.50')
        ->assertJsonPath('currency', 'ETB')
        ->assertJsonPath('effective_from', '2026-10-19T08:00:00+00:00')
        ->assertJsonPath('effective_to', null)
        ->assertJsonPath('is_current', true)
        ->assertJsonPath('created_by_user_id', $this->admin->id);

    expect(AuditLog::query()->where('action', 'item.price.set')->where('subject_id', $response->json('id'))->exists())->toBeTrue()
        ->and($this->prices->at($this->item, CarbonImmutable::now())?->amount())->toBe('45.50');
})->group('module:catalog', 'feature');

it('closes the open price at the new effective_from and keeps the old row', function () {
    $this->travelTo('2026-10-01 06:00:00');
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$this->item->id}/prices", ['price' => '40.00'])->assertCreated();

    $this->travelTo('2026-10-19 09:30:00');
    $second = $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$this->item->id}/prices", ['price' => '45.00'])->assertCreated();

    $rows = ItemPrice::query()->where('item_id', $this->item->id)->orderBy('effective_from')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->price->amount())->toBe('40.00')
        ->and($rows[0]->effective_to?->toIso8601String())->toBe('2026-10-19T09:30:00+00:00')
        ->and($rows[1]->id)->toBe($second->json('id'))
        ->and($rows[1]->effective_to)->toBeNull();

    $history = $this->getJson("/api/v1/items/{$this->item->id}/prices")->assertOk()->json('data');
    expect(array_column($history, 'price'))->toBe(['45.00', '40.00'])
        ->and(array_column($history, 'is_current'))->toBe([true, false]);
})->group('module:catalog', 'feature');

it('answers Prices::at with effective_from inclusive and effective_to exclusive', function () {
    $from = CarbonImmutable::parse('2026-10-01 06:00:00', 'UTC');
    $switch = CarbonImmutable::parse('2026-10-19 09:30:00', 'UTC');
    ItemPrice::factory()->create(['branch_id' => $this->branch->id, 'item_id' => $this->item->id, 'price' => '40.00', 'effective_from' => $from, 'effective_to' => $switch]);
    ItemPrice::factory()->create(['branch_id' => $this->branch->id, 'item_id' => $this->item->id, 'price' => '45.00', 'effective_from' => $switch, 'effective_to' => null]);

    expect($this->prices->at($this->item, $from->subSecond()))->toBeNull()
        ->and($this->prices->at($this->item, $from)?->amount())->toBe('40.00')
        ->and($this->prices->at($this->item, $switch->subSecond())?->amount())->toBe('40.00')
        ->and($this->prices->at($this->item, $switch)?->amount())->toBe('45.00')
        ->and($this->prices->at($this->item->id, $switch->addYear())?->amount())->toBe('45.00')
        ->and($this->prices->at($this->item, $switch))->toBeInstanceOf(Money::class)
        ->and($this->prices->at($this->item, $switch)?->currency())->toBe('ETB');
})->group('module:catalog', 'feature');

it('compares instants, not wall clocks: a time given in Addis Ababa finds the same price', function () {
    $switch = CarbonImmutable::parse('2026-10-19 09:30:00', 'UTC');
    ItemPrice::factory()->create(['branch_id' => $this->branch->id, 'item_id' => $this->item->id, 'price' => '40.00', 'effective_from' => $switch->subMonth(), 'effective_to' => $switch]);
    ItemPrice::factory()->create(['branch_id' => $this->branch->id, 'item_id' => $this->item->id, 'price' => '45.00', 'effective_from' => $switch, 'effective_to' => null]);

    // 12:29:59 in Addis Ababa (UTC+3) is 09:29:59 UTC: still the old price
    expect($this->prices->at($this->item, CarbonImmutable::parse('2026-10-19 12:29:59', 'Africa/Addis_Ababa'))?->amount())->toBe('40.00')
        ->and($this->prices->at($this->item, CarbonImmutable::parse('2026-10-19 12:30:00', 'Africa/Addis_Ababa'))?->amount())->toBe('45.00');
})->group('module:catalog', 'feature');

it('schedules a future price: the current one stays effective until then', function () {
    $this->travelTo('2026-10-19 08:00:00');
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$this->item->id}/prices", ['price' => '40.00'])->assertCreated();
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$this->item->id}/prices", ['price' => '50.00', 'effective_from' => '2026-11-01T00:00:00+03:00'])
        ->assertCreated()
        ->assertJsonPath('effective_from', '2026-10-31T21:00:00+00:00')
        ->assertJsonPath('is_current', false);

    expect($this->prices->at($this->item, CarbonImmutable::now())?->amount())->toBe('40.00')
        ->and($this->prices->at($this->item, CarbonImmutable::parse('2026-10-31 21:00:00', 'UTC'))?->amount())->toBe('50.00');
})->group('module:catalog', 'feature');

it('refuses a price that does not start after the open one, and validates the amount as a decimal string', function () {
    $this->travelTo('2026-10-19 08:00:00');
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$this->item->id}/prices", ['price' => '40.00'])->assertCreated();

    $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$this->item->id}/prices", ['price' => '41.00', 'effective_from' => '2026-10-19T08:00:00Z'])
        ->assertStatus(422)->assertJsonPath('message_key', 'catalog.price.too_early');
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$this->item->id}/prices", ['price' => '41.00', 'effective_from' => '2026-10-18T08:00:00Z'])
        ->assertStatus(422)->assertJsonPath('message_key', 'catalog.price.too_early');

    foreach ([12.5, '-1.00', '1.234', 'abc', ''] as $bad) {
        $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$this->item->id}/prices", ['price' => $bad])
            ->assertStatus(422)->assertJsonValidationErrors(['price']);
    }

    expect(ItemPrice::query()->where('item_id', $this->item->id)->count())->toBe(1);
})->group('module:catalog', 'feature');

it('refuses price writes to every role but admin while every role reads the history', function () {
    foreach (array_diff(identityRoles(), ['admin']) as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson("/api/v1/items/{$this->item->id}/prices")->assertOk();
        $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$this->item->id}/prices", ['price' => '1.00'])->assertStatus(403);
    }
    expect(ItemPrice::query()->count())->toBe(0);
})->group('module:catalog', 'feature');

it('replays a price change idempotently', function () {
    assertIdempotent($this, 'POST', "/api/v1/items/{$this->item->id}/prices", ['price' => '12.00'], 'item_prices');
})->group('module:catalog', 'feature');

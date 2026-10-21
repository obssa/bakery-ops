<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Orders\Models\OrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OrderFloor;

/*
 * Phase 2 gate, recipe versions (phase-2 plan sections 6 item 4 and 7): publishing a version keeps the old one readable by
 * `version`, leaves exactly one active, and does not touch an order already sent. A line sent under version 1 reserved version
 * 1's quantities; consumption at READY follows that reservation (architecture rule 3), so a version published between send
 * and ready changes what the next order reserves and nothing about what this one consumes.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $this->menu = $this->floor->menu;
    // the sandwich as version 2: more chicken, less lettuce, no onion
    $this->publishV2 = function (): void {
        $m = $this->menu;
        loginAs($this->floor->admin);
        $this->withHeaders(idempotencyKey())->postJson("/api/v1/items/{$m->sandwich->id}/recipe/versions", [
            'yield_qty' => '1', 'yield_unit_id' => catalogUnit('pcs')->id, 'change_note' => 'A bigger sandwich',
            'lines' => [
                ['ingredient_item_id' => $m->bread->id, 'qty' => '2', 'unit_id' => catalogUnit('slice')->id],
                ['ingredient_item_id' => $m->chicken->id, 'qty' => '200', 'unit_id' => catalogUnit('g')->id, 'waste_pct' => '5.00'],
                ['ingredient_item_id' => $m->lettuce->id, 'qty' => '5', 'unit_id' => catalogUnit('g')->id],
                ['ingredient_item_id' => $m->butter->id, 'qty' => '10', 'unit_id' => catalogUnit('g')->id],
            ],
        ])->assertCreated()->assertJsonPath('version', 2);
    };
});

it('keeps version 1 readable by version after version 2 is published, with exactly one version active', function () {
    $m = $this->menu;
    $recipes = app(Recipes::class);
    $before = $recipes->version($m->sandwich, 1);

    ($this->publishV2)();

    expect($recipes->version($m->sandwich, 1))->toEqual($before)
        ->and($recipes->version($m->sandwich, 2)?->version)->toBe(2)
        ->and($recipes->activeFor($m->sandwich)?->version)->toBe(2)
        ->and(Recipe::query()->where('product_item_id', $m->sandwich->id)->where('is_active', true)->count())->toBe(1);
})->group('gate:p2', 'feature', 'module:catalog');

it('lets an order sent under version 1 consume what it reserved after version 2 is published, and reserves version 2 for the next order', function () {
    $f = $this->floor;
    $m = $this->menu;
    $items = $f->sandwichIngredients();

    $first = $f->send([$m->line($m->sandwich)]);
    $line = $f->lineOf($first, $m->sandwich);
    $ref = $f->refOf($line['client_uuid']);
    $reserved = $f->moved($ref, 'reservation', $items, $m->kitchen);
    expect($reserved)->not->toBe([])
        ->and(OrderLine::query()->findOrFail($line['id'])->recipe_version)->toBe(1)
        ->and($reserved[$m->chicken->id])->toBe('126.0000', 'version 1: 120 g with 5 % waste');

    ($this->publishV2)();

    // the reservation in flight is untouched by the publish
    expect($f->outstanding($ref))->toBe($reserved);

    $f->act('accept', $line['id'], $f->chef)->assertOk();
    $f->act('ready', $line['id'], $f->chef)->assertOk();

    $consumed = $f->moved($ref, 'production_consume', $items, $m->kitchen);
    expect(array_map(fn (string $q): string => ltrim($q, '-'), $consumed))->toBe($reserved)
        ->and($f->outstanding($ref))->toBe([])
        ->and(OrderLine::query()->findOrFail($line['id'])->recipe_version)->toBe(1);

    // an order sent now is snapshotted and reserved under version 2
    $second = $f->send([$m->line($m->sandwich)]);
    $next = $f->lineOf($second, $m->sandwich);
    $nextReserved = $f->moved($f->refOf($next['client_uuid']), 'reservation', $items, $m->kitchen);
    expect(OrderLine::query()->findOrFail($next['id'])->recipe_version)->toBe(2)
        ->and($nextReserved[$m->chicken->id])->toBe('210.0000', 'version 2: 200 g with 5 % waste')
        ->and(array_key_exists($m->onion->id, $nextReserved))->toBeFalse('version 2 has no onion')
        ->and(array_key_exists($m->onion->id, $reserved))->toBeTrue();
    $oracle = ledgerOracle($f->branch->id);
    expect($oracle['balances'])->toBe($oracle['movements'])->and($oracle['view'])->toBe($oracle['movements']);
})->group('gate:p2', 'feature', 'module:catalog', 'module:orders');

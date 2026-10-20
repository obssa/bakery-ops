<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;

/*
 * Phase 1 gate (rule 3, ADR 0008): one order through the whole flow leaves the ledger with exactly the movement
 * sequence the plan states: `reservation -> production_consume` for the ingredients of each station line (sandwich,
 * latte) and `reservation -> sale` for the counter line (croissant); and at the end `available` is what was seeded minus
 * what was sold or made. The same flow in a browser is the `p1-order-flow` Playwright project.
 */

uses(RefreshDatabase::class);

it('moves stock reservation, then consume at ready, then sale at payment, and leaves available = seeded - used', function () {
    $f = OrderFloor::open($this);
    $m = $f->menu;
    $seeded = [
        'chicken' => $f->level($m->chicken, $m->kitchen)->onHand->toString(),
        'milk' => $f->level($m->milk, $m->bar)->onHand->toString(),
        'croissant' => $f->level($m->croissant, $m->display)->onHand->toString(),
    ];
    $order = $f->send([$m->line($m->sandwich), $m->line($m->latte), $m->line($m->croissant)]);
    $sandwich = $f->lineOf($order, $m->sandwich);
    $latte = $f->lineOf($order, $m->latte);
    $croissant = $f->lineOf($order, $m->croissant);
    $refs = [$f->refOf($sandwich['client_uuid']), $f->refOf($latte['client_uuid']), $f->refOf($croissant['client_uuid'])];

    // sent: reserved, nothing consumed yet
    expect($f->level($m->croissant, $m->display)->reserved->toString())->toBe('1.0000')
        ->and($f->level($m->chicken, $m->kitchen)->onHand->toString())->toBe($seeded['chicken']);

    $f->act('accept', $sandwich['id'], $f->chef)->assertOk();
    $f->act('accept', $latte['id'], $f->barista)->assertOk();
    $f->act('ready', $sandwich['id'], $f->chef)->assertOk();
    $f->act('ready', $latte['id'], $f->barista)->assertOk();
    foreach ([$sandwich, $latte, $croissant] as $line) {
        $f->act('served', $line['id'], $f->waiter)->assertOk();
    }
    loginAs($f->cashier);
    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/orders/{$order['id']}/payments", [
        'client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => $order['totals']['total'], 'tendered_amount' => '1000.00',
    ])->assertCreated();

    $reasons = fn (string $ref, array $items, $location): array => array_values(array_unique(array_map(
        fn ($movement) => $movement->reason->value,
        $f->movementsOf($ref, $items, $location),
    )));
    $sandwichItems = $f->sandwichIngredients();
    expect($reasons($refs[0], $sandwichItems, $m->kitchen))->toBe(['reservation', 'production_consume'])
        ->and($reasons($refs[1], [$m->espresso, $m->milk], $m->bar))->toBe(['reservation', 'production_consume'])
        ->and($reasons($refs[2], [$m->croissant], $m->display))->toBe(['reservation', 'sale']);

    // available = seeded - used: the croissant is sold, the chicken and the milk are made into food
    $croissantLevel = $f->level($m->croissant, $m->display);
    expect($croissantLevel->onHand->toString())->toBe('4.0000')
        ->and($croissantLevel->available()->toString())->toBe('4.0000')
        ->and($f->level($m->chicken, $m->kitchen)->reserved->isZero())->toBeTrue()
        ->and($f->level($m->chicken, $m->kitchen)->available()->toString())->not->toBe($seeded['chicken'])
        ->and($f->level($m->milk, $m->bar)->available()->toString())->toBe($f->level($m->milk, $m->bar)->onHand->toString());
    $oracle = ledgerOracle($f->branch->id);
    expect($oracle['balances'])->toBe($oracle['movements'])->and($oracle['view'])->toBe($oracle['movements']);
})->group('gate:p1', 'feature', 'module:orders');

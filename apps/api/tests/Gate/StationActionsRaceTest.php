<?php

declare(strict_types=1);

use App\Modules\Orders\Models\OrderLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;
use Tests\Support\OrderFloor;

/*
 * Ready consumes exactly once under real concurrency (docs/phases/phase-1-plan.md section 6 item 3 "1.3c", section 7
 * Concurrency; ADR 0008): the kitchen screen double-fires, or the chef and the manager tap Ready on the same ticket at
 * the same moment, or the chef taps Ready while another cook taps Reject. Each request runs in its own process on its
 * own database connection (Forks::together) through the whole HTTP stack, so the order and line row locks, the state
 * machine and Reservations::consume / release really race. Exactly one wins; the ledger shows one production_consume
 * per ingredient or one release per ingredient, never both and never twice. CommitsForConcurrency: the children see
 * committed rows only. The ledger is read through Inventory's contracts (Stock, Reservations).
 */

uses(CommitsForConcurrency::class);

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $m = $this->floor->menu;
    $order = $this->floor->send([$m->line($m->sandwich)]);
    $this->line = $order['lines'][0];
    $this->ref = $this->floor->refOf($this->line['client_uuid']);
    $this->job = fn (string $action, $as, array $body = [], ?string $key = null): Closure => function () use ($action, $as, $body, $key): array {
        $response = $this->floor->act($action, $this->line['id'], $as, $body, $key);

        return ['status' => $response->getStatusCode(), 'message_key' => $response->json('message_key'), 'line_status' => $response->json('status'), 'replayed' => $response->headers->get('Idempotency-Replayed')];
    };
    $this->settled = function (): array {
        $f = $this->floor;

        return [
            'consumed' => $f->moved($this->ref, 'production_consume', $f->sandwichIngredients(), $f->menu->kitchen),
            'released' => $f->moved($this->ref, 'reservation_release', $f->sandwichIngredients(), $f->menu->kitchen),
        ];
    };
});

it('consumes once when two people mark the same line ready at the same moment', function () {
    $f = $this->floor;
    $m = $f->menu;

    $results = Forks::together([($this->job)('ready', $f->chef), ($this->job)('ready', $f->manager)]);

    foreach ($results as $i => $result) {
        expect($result['ok'] ?? false)->toBeTrue("ready {$i}: ".($result['error'] ?? ''));
    }
    $statuses = collect($results)->pluck('value.status')->sort()->values()->all();
    expect($statuses)->toBe([200, 409], 'exactly one ready wins')
        ->and(collect($results)->pluck('value')->firstWhere('status', 409)['message_key'])->toBe('state_machine.illegal_transition');
    $settled = ($this->settled)();
    expect($settled['consumed'])->toEqual([
        $m->bread->id => '-2.0000', $m->chicken->id => '-126.0000', $m->lettuce->id => '-20.0000', $m->onion->id => '-15.0000', $m->butter->id => '-10.0000',
    ])
        ->and($settled['released'])->toBe([])
        ->and($f->outstanding($this->ref))->toBe([])
        ->and($f->level($m->chicken, $m->kitchen)->onHand->toString())->toBe('874.0000')
        ->and($f->level($m->chicken, $m->kitchen)->reserved->toString())->toBe('0.0000')
        ->and(DB::table('audit_log')->where('action', 'order_line.ready')->count())->toBe(1)
        ->and(OrderLine::query()->findOrFail($this->line['id'])->status->value)->toBe('ready');
    $oracle = ledgerOracle($f->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
})->group('concurrency', 'module:orders', 'feature');

it('settles the reservation one way only when Ready and Reject race on the same line', function () {
    $f = $this->floor;

    $results = Forks::together([($this->job)('ready', $f->chef), ($this->job)('reject', $f->manager, ['reason' => 'equipment_down'])]);

    foreach ($results as $i => $result) {
        expect($result['ok'] ?? false)->toBeTrue("action {$i}: ".($result['error'] ?? ''));
    }
    $values = collect($results)->pluck('value');
    expect($values->pluck('status')->sort()->values()->all())->toBe([200, 409], 'exactly one of them wins');
    $winner = $values->firstWhere('status', 200)['line_status'];
    $settled = ($this->settled)();
    expect(OrderLine::query()->findOrFail($this->line['id'])->status->value)->toBe($winner)
        ->and($winner === 'ready' ? $settled['consumed'] : $settled['released'])->toHaveCount(5)
        ->and($winner === 'ready' ? $settled['released'] : $settled['consumed'])->toBe([], 'never consumed and released both')
        ->and($f->outstanding($this->ref))->toBe([]);
    $oracle = ledgerOracle($f->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
})->group('concurrency', 'module:orders', 'feature');

it('consumes once when the same ready arrives twice at once under one Idempotency-Key', function () {
    config(['cache.default' => 'database']); // the key lock must be shared between the two processes, as on the live host
    $f = $this->floor;
    $key = (string) Str::uuid();

    $results = Forks::together([($this->job)('ready', $f->chef, [], $key), ($this->job)('ready', $f->chef, [], $key)]);

    foreach ($results as $i => $result) {
        expect($result['ok'] ?? false)->toBeTrue("ready {$i}: ".($result['error'] ?? ''));
    }
    $values = collect($results)->pluck('value');
    expect($values->where('status', 200))->not->toBeEmpty();
    foreach ($values as $value) {
        $inFlight = $value['status'] === 409 && $value['message_key'] === 'idempotency.in_flight';
        $same = $value['status'] === 200 && $value['line_status'] === 'ready';
        expect($inFlight || $same)->toBeTrue('the second one waits (409 idempotency.in_flight) or replays: '.json_encode($value));
    }
    expect(($this->settled)()['consumed'])->toHaveCount(5)
        ->and(DB::table('audit_log')->where('action', 'order_line.ready')->count())->toBe(1);
})->group('concurrency', 'module:orders', 'feature');

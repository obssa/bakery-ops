<?php

declare(strict_types=1);

use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Tests\Support\ChainFixture;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * GET /locations/{location}/chain and /chain/verify (step 3.4; phase-3 plan section 4, "Chain"): the links of a place newest first
 * with the head, and the verification of the whole chain. Both need shifts.replay (admin, manager) and are branch-scoped (another
 * branch's place is a 404). A read: no Idempotency-Key.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->chain = new ChainFixture($this->f);
    $this->place = $this->f->place($this->f->kitchen);
    $this->get = function ($as, string $path) {
        loginAs($as);

        return $this->getJson("/api/v1/locations/{$this->place->id}/chain{$path}");
    };
});

it('lists the links newest first with the head', function () {
    $links = $this->chain->chain($this->place, 3);

    $response = ($this->get)($this->f->admin, '')->assertOk();

    $third = StockCount::query()->findOrFail($links[2]->id);
    $response->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.chain_seq', 3)
        ->assertJsonPath('data.0.count_id', $third->id)
        ->assertJsonPath('data.0.type', 'closing')
        ->assertJsonPath('data.0.shift_id', $third->shift_instance_id)
        ->assertJsonPath('data.0.previous_hash', $third->previous_hash)
        ->assertJsonPath('data.0.payload_hash', $third->payload_hash)
        ->assertJsonPath('data.0.superseded_by_id', null)
        ->assertJsonPath('data.2.chain_seq', 1)
        ->assertJsonPath('head.chain_seq', 3)
        ->assertJsonPath('head.payload_hash', $third->payload_hash);
    expect($response->json('data.0.shift_date'))->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and($response->json('data.0.locked_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T/');
});

it('pages with limit and before_seq, and caps the limit at 200', function () {
    $this->chain->chain($this->place, 5);

    ($this->get)($this->f->admin, '?limit=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.chain_seq', 5)->assertJsonPath('head.chain_seq', 5);
    ($this->get)($this->f->admin, '?limit=2&before_seq=4')->assertOk()->assertJsonPath('data.0.chain_seq', 3)->assertJsonPath('data.1.chain_seq', 2)->assertJsonPath('head.chain_seq', 5);
    ($this->get)($this->f->admin, '?limit=201')->assertStatus(422);
    ($this->get)($this->f->admin, '?limit=0')->assertStatus(422);
});

it('answers an empty chain with no head', function () {
    ($this->get)($this->f->manager, '')->assertOk()->assertJsonPath('data', [])->assertJsonPath('head', null);
    ($this->get)($this->f->manager, '/verify')->assertOk()->assertExactJson(['ok' => true, 'checked' => 0, 'head_hash' => null, 'failures' => []]);
});

it('verifies the chain: ok, how many links, the head hash', function () {
    $links = $this->chain->chain($this->place, 3);

    ($this->get)($this->f->admin, '/verify')->assertOk()->assertExactJson([
        'ok' => true, 'checked' => 3, 'head_hash' => StockCount::query()->findOrFail($links[2]->id)->payload_hash, 'failures' => [],
    ]);
});

it('is for the people who may replay: the admin and the manager', function (string $who, int $status) {
    $this->chain->chain($this->place, 1);

    ($this->get)($this->f->{$who}, '')->assertStatus($status);
    ($this->get)($this->f->{$who}, '/verify')->assertStatus($status);
})->with([['admin', 200], ['manager', 200], ['chef', 403], ['cashier', 403], ['waiter', 403]]);

it('does not find the place of another branch', function () {
    $other = Branch::factory()->create();
    $f2 = ShiftsFixture::build($other, $this);
    actInBranch($this->branch);
    $foreign = $f2->place($f2->kitchen);
    loginAs($this->f->admin);

    $this->getJson("/api/v1/locations/{$foreign->id}/chain")->assertNotFound();
    $this->getJson("/api/v1/locations/{$foreign->id}/chain/verify")->assertNotFound();
});

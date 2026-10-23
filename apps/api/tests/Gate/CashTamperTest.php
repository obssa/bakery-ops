<?php

declare(strict_types=1);

use App\Modules\Shifts\Contracts\HashChain;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ChainFixture;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

uses(CommitsForConcurrency::class);

/*
 * A changed cash figure is a tamper the chain catches (step 3.5; ADR 0012; phase-3 plan sections 3 and 10.3). The cash block of a till
 * count's payload is rebuilt from its declaration row on every verification, so changing any figure of that row after the lock, by a
 * person with write access to the database who has dropped the freeze trigger (ChainFixture::withoutTriggers; DDL commits, hence
 * CommitsForConcurrency), makes the rebuilt bytes differ from the stored ones: payload_mismatch, naming the link. With the trigger in
 * place the same UPDATE is refused. A link locked before the cash block existed (cash: null) keeps verifying.
 */

const CASH_TRIGGERS = ['cash_declarations_freeze_update', 'cash_declarations_forbid_delete'];

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
    $this->drawer = $this->f->drawer($this->f->till);
    $cashier = $this->f->cashier;
    $o = $this->f->openTill($this->f->till, $cashier, ['100' => 5]);
    $this->f->taken($o['shift'], '60.00', '100.00');
    $c = $this->f->closeTill($o['shift'], $cashier);
    $this->f->cashPut($cashier, $c['count'], ['denominations' => ['100' => 5, '50' => 1], 'float_left_total' => '100.00', 'reason_id' => $this->f->reasonId('miscount'), 'reason_note' => 'a note'])->assertOk();
    $this->f->submit($cashier, $c['count'])->assertOk()->assertJsonPath('status', 'pending_approval');
    $this->f->approve($this->f->manager, $c['count'])->assertOk()->assertJsonPath('status', 'locked');
    $this->count = $c['count'];
    $this->verify = fn (): array => app(HashChain::class)->verify($this->drawer->id)->toArray();
});

it('verifies the untouched chain of a till with cash', function () {
    expect(($this->verify)())->toMatchArray(['ok' => true, 'checked' => 2, 'failures' => []]);
});

it('refuses a change to the declaration of a locked count while the trigger stands', function (string $column, mixed $value) {
    expect(fn () => DB::table('cash_declarations')->where('count_id', $this->count)->update([$column => $value]))->toThrow(QueryException::class);
    expect(($this->verify)()['ok'])->toBeTrue();
})->with([
    ['counted_total', '9999.00'],
    ['variance_note', 'edited'],
]);

it('finds a changed cash figure and names the link and how', function (string $column, mixed $value) {
    ChainFixture::withoutTriggers(CASH_TRIGGERS, fn () => DB::table('cash_declarations')->where('count_id', $this->count)->update([$column => $value]));

    $verdict = ($this->verify)();

    expect($verdict['ok'])->toBeFalse()
        ->and($verdict['failures'])->toBe([['chain_seq' => 2, 'count_id' => $this->count, 'kind' => 'payload_mismatch']]);
})->with([
    'counted total' => ['counted_total', '9999.00'],
    'expected total' => ['expected_total', '1.00'],
    'variance total' => ['variance_total', '0.00'],
    'cash sales' => ['cash_sales_total', '61.00'],
    'float left' => ['float_left_total', '50.00'],
    'denominations' => ['denominations', json_encode(['200' => 0, '100' => 6, '50' => 0, '10' => 0, '5' => 0, '1' => 0, '0.50' => 0, '0.25' => 0, '0.10' => 0, '0.05' => 0])],
    'the note' => ['variance_note', 'nothing happened'],
    'the channels' => ['channel_counts', '[]'],
]);

it('finds a changed reason', function () {
    $other = $this->f->reasonId('spoilage');

    ChainFixture::withoutTriggers(CASH_TRIGGERS, fn () => DB::table('cash_declarations')->where('count_id', $this->count)->update(['variance_reason_id' => $other]));

    expect(($this->verify)()['failures'])->toBe([['chain_seq' => 2, 'count_id' => $this->count, 'kind' => 'payload_mismatch']]);
});

it('finds a deleted declaration', function () {
    ChainFixture::withoutTriggers(CASH_TRIGGERS, fn () => DB::table('cash_declarations')->where('count_id', $this->count)->delete());

    expect(($this->verify)()['failures'])->toBe([['chain_seq' => 2, 'count_id' => $this->count, 'kind' => 'payload_mismatch']]);
});

it('puts the triggers back after a tamper, exactly as they were', function () {
    $before = array_map(fn (string $name): string => (string) ((array) DB::selectOne("SHOW CREATE TRIGGER `{$name}`"))['SQL Original Statement'], CASH_TRIGGERS);

    ChainFixture::withoutTriggers(CASH_TRIGGERS, fn () => null);

    $after = array_map(fn (string $name): string => (string) ((array) DB::selectOne("SHOW CREATE TRIGGER `{$name}`"))['SQL Original Statement'], CASH_TRIGGERS);
    expect($after)->toBe($before);
});

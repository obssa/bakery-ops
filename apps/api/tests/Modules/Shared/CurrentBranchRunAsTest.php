<?php

declare(strict_types=1);

use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchNotResolved;
use App\Shared\Tenancy\CurrentBranch;

/*
 * Phase 4, step 4.4a (plan section 6 item 4): a queued or console rebuild has no request, so nothing resolves its branch, and an
 * unresolved CurrentBranch means BranchScoped filters NOTHING (console semantics): a Facts port read then would mix every branch.
 * CurrentBranch::runAs(branchId, callback) resolves the branch for the length of the callback and puts back exactly what was there
 * (the twin of ActorContext::runAs, ADR 0011), even when the callback throws, so a worker that rebuilds many branches in one process
 * never reads one branch's facts under another.
 */

beforeEach(function () {
    $this->bole = Branch::factory()->create(['name' => 'Bole']);
    $this->piassa = Branch::factory()->create(['name' => 'Piassa']);
    $this->boleKitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->bole->id]);
    Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->piassa->id]);
});

it('resolves the branch for the callback, so branch-scoped reads see that branch only, and answers the callback\'s result', function () {
    $current = app(CurrentBranch::class);

    $seen = $current->runAs($this->bole->id, fn (): array => [$current->id(), Station::query()->pluck('id')->all()]);

    expect($seen)->toBe([$this->bole->id, [$this->boleKitchen->id]]);
})->group('module:shared', 'feature');

it('puts back exactly what was there: unresolved stays unresolved, a resolved branch comes back', function () {
    $current = app(CurrentBranch::class);

    $current->runAs($this->bole->id, fn () => null);
    expect($current->isResolved())->toBeFalse();

    $current->set($this->piassa);
    $current->runAs($this->bole->id, fn () => null);
    expect($current->id())->toBe($this->piassa->id);
})->group('module:shared', 'feature');

it('puts the previous branch back when the callback throws, and rethrows', function () {
    $current = app(CurrentBranch::class);
    $current->set($this->piassa);

    expect(fn () => $current->runAs($this->bole->id, function (): never {
        throw new RuntimeException('the rebuild failed');
    }))->toThrow(RuntimeException::class, 'the rebuild failed');

    expect($current->id())->toBe($this->piassa->id);
})->group('module:shared', 'feature');

it('refuses a branch that does not exist without running the callback, and changes nothing', function () {
    $current = app(CurrentBranch::class);
    $ran = false;

    expect(fn () => $current->runAs(999_999, function () use (&$ran): void {
        $ran = true;
    }))->toThrow(BranchNotResolved::class);

    expect($ran)->toBeFalse()->and($current->isResolved())->toBeFalse();
})->group('module:shared', 'feature');

it('nests: the inner run sees its own branch and the outer one gets its branch back', function () {
    $current = app(CurrentBranch::class);
    $trail = [];

    $current->runAs($this->bole->id, function () use ($current, &$trail): void {
        $trail[] = $current->id();
        $current->runAs($this->piassa->id, function () use ($current, &$trail): void {
            $trail[] = $current->id();
        });
        $trail[] = $current->id();
    });

    expect($trail)->toBe([$this->bole->id, $this->piassa->id, $this->bole->id])->and($current->isResolved())->toBeFalse();
})->group('module:shared', 'feature');

<?php

declare(strict_types=1);

use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchNotResolved;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Tenancy\CurrentBranch;

/*
 * Rule 8: branch_id on every tenant table, and reads never cross branches. BranchScoped is the trait that makes
 * that automatic: a global scope on the resolved CurrentBranch and branch_id filled on create.
 */

beforeEach(function () {
    $this->a = Branch::factory()->create(['name' => 'Bole']);
    $this->b = Branch::factory()->create(['name' => 'Piassa']);
    $this->aKitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->a->id]);
    $this->bKitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->b->id]);
});

it('filters every query to the resolved branch', function () {
    actInBranch($this->a);

    expect(Station::query()->pluck('id')->all())->toBe([$this->aKitchen->id])
        ->and(Station::query()->find($this->bKitchen->id))->toBeNull()
        ->and(Station::query()->count())->toBe(1);
})->group('module:shared', 'feature');

it('leaves queries unfiltered when no branch is resolved (console, seeders)', function () {
    expect(app(CurrentBranch::class)->isResolved())->toBeFalse()
        ->and(Station::query()->count())->toBe(2)
        ->and(fn () => currentBranch())->toThrow(BranchNotResolved::class);
})->group('module:shared', 'feature');

it('fills branch_id on create from the resolved branch', function () {
    actInBranch($this->b);

    $bar = Station::query()->create(['code' => StationCode::Bar, 'name_en' => 'Bar', 'name_am' => 'ባር']);

    expect($bar->branch_id)->toBe($this->b->id)
        ->and($bar->fresh()?->branch_id)->toBe($this->b->id);
})->group('module:shared', 'feature');

it('can be lifted explicitly for cross-branch admin work', function () {
    actInBranch($this->a);

    expect(Station::query()->withoutGlobalScope(BranchScope::class)->count())->toBe(2)
        ->and(Station::query()->forBranch($this->b->id)->pluck('id')->all())->toBe([$this->bKitchen->id]);
})->group('module:shared', 'feature');

it('exposes the branch relation and the branch exposes its stations', function () {
    actInBranch($this->a);

    expect($this->aKitchen->branch->is($this->a))->toBeTrue()
        ->and($this->a->stations()->pluck('id')->all())->toBe([$this->aKitchen->id]);
})->group('module:shared', 'feature');

it('reads timezone and currency from the resolved branch', function () {
    actInBranch($this->a);
    $current = app(CurrentBranch::class);

    expect($current->id())->toBe($this->a->id)
        ->and($current->timezone())->toBe('Africa/Addis_Ababa')
        ->and($current->currency())->toBe('ETB');
})->group('module:shared', 'feature');

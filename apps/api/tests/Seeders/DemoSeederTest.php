<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchScope;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\DemoSeeder;
use Database\Seeders\Phase0Seeder;
use Spatie\Permission\Models\Role;

/*
 * testing-strategy.md, Seeds: `DemoSeeder` composes Phase0Seeder .. PhaseNSeeder up to SEED_PHASE (default: every
 * phase seeder that exists), so a phase's e2e job seeds exactly the world its gate expects and later phases extend it.
 * CI's e2e job runs `SEED_PHASE=<n> php artisan migrate:fresh --seed --seeder=DemoSeeder` on a database without roles.
 */

afterEach(function () {
    putenv('SEED_PHASE');
});

it('lists the phase seeders that exist, in phase order, up to the requested phase', function () {
    expect(DemoSeeder::seedersUpTo(0))->toBe([Phase0Seeder::class])
        ->and(DemoSeeder::seedersUpTo(5)[0])->toBe(Phase0Seeder::class)
        ->and(DemoSeeder::seedersUpTo(-1))->toBe([]);
    foreach (DemoSeeder::seedersUpTo(5) as $class) {
        expect(class_exists($class))->toBeTrue()->and($class)->toMatch('/\\\\Phase[0-5]Seeder$/');
    }
})->group('feature', 'seeds');

it('reads the last phase from SEED_PHASE, every phase when it is not set', function () {
    expect(DemoSeeder::maxPhase())->toBe(5);
    putenv('SEED_PHASE=0');
    expect(DemoSeeder::maxPhase())->toBe(0);
})->group('feature', 'seeds');

it('seeds the demo bakery on a database without roles, as the e2e job does with SEED_PHASE=0', function () {
    putenv('SEED_PHASE=0');
    expect(Role::query()->count())->toBe(0);

    $this->seed(DemoSeeder::class);

    $branch = Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->sole();
    expect(Role::query()->count())->toBe(8)
        ->and(Item::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $branch->id)->count())->toBeGreaterThan(60);
})->group('feature', 'seeds');

it('seeds the role matrix and then the demo bakery with `db:seed`', function () {
    $this->seed();

    expect(Role::query()->count())->toBe(8)
        ->and(Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->count())->toBe(1);
})->group('feature', 'seeds');

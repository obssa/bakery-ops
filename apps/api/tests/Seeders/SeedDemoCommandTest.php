<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Models\User;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchScope;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\Demo\DemoCredentials;
use Illuminate\Support\Facades\Artisan;

/*
 * `bakery:seed-demo {--branch=} {--phase=} {--force}`: loads the demo bakery (DemoSeeder's phase seeders up to --phase)
 * into a branch of its own, on the live site too (docs/runbook/server.md). Production needs --force and
 * BAKERY_DEMO_PASSWORD; nothing is written when either is missing. It prints what the branch holds and the short
 * codes of the accounts it created (once), never the password.
 */

function demoUsersOf(string $branchName): int
{
    $branch = Branch::query()->where('name', $branchName)->first();

    return $branch === null ? 0 : User::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $branch->id)->count();
}

it('loads the demo bakery into "Demo Bakery" and reports what it holds, short codes once, never the password', function () {
    $exit = Artisan::call('bakery:seed-demo');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and(demoUsersOf(DemoBakery::DEFAULT_BRANCH))->toBe(10)
        ->and($output)->toContain(DemoBakery::DEFAULT_BRANCH)
        ->and($output)->toContain('ingredients')
        ->and($output)->toContain('stock_movements')
        ->and($output)->toContain(DemoBakery::ADMIN_PHONE)
        ->and($output)->not->toContain(DemoCredentials::LOCAL_PASSWORD);

    $codes = DemoCredentials::shortCodes(array_column(DemoBakery::PEOPLE, 'phone'), DemoCredentials::LOCAL_PASSWORD);
    foreach ($codes as $code) {
        expect($output)->toContain($code);
    }

    // a second run adds nothing and shows no code again
    $exit = Artisan::call('bakery:seed-demo');
    $again = Artisan::output();
    expect($exit)->toBe(0)
        ->and(demoUsersOf(DemoBakery::DEFAULT_BRANCH))->toBe(10)
        ->and(Branch::query()->count())->toBe(1)
        ->and($again)->toContain('no new accounts');
    foreach ($codes as $code) {
        expect($again)->not->toMatch('/\b'.$code.'\b/');
    }
})->group('feature', 'seeds');

it('seeds into the branch named by --branch, up to --phase', function () {
    $this->artisan('bakery:seed-demo', ['--branch' => 'Bole Demo', '--phase' => '0'])->assertSuccessful();

    expect(demoUsersOf('Bole Demo'))->toBe(9)
        ->and(Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->exists())->toBeFalse();
})->group('feature', 'seeds');

it('rejects a phase outside 0 to 5 or an empty branch name, and writes nothing', function (array $options) {
    $this->artisan('bakery:seed-demo', $options)->assertFailed();

    expect(Branch::query()->count())->toBe(0);
})->with([
    'phase 6' => [['--phase' => '6']],
    'phase "one"' => [['--phase' => 'one']],
    'blank branch' => [['--branch' => '   ']],
])->group('feature', 'seeds');

it('refuses a branch that exists and is not a demo branch', function () {
    $shop = Branch::factory()->create(['name' => 'Main']);

    $this->artisan('bakery:seed-demo', ['--branch' => 'Main'])->assertFailed();

    expect(User::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $shop->id)->count())->toBe(0);
})->group('feature', 'seeds');

it('refuses production without --force, and with --force but no BAKERY_DEMO_PASSWORD; nothing is written', function () {
    app()->detectEnvironment(fn (): string => 'production');
    config(['bakery.demo.password' => null]);

    $this->artisan('bakery:seed-demo')->assertFailed();
    $this->artisan('bakery:seed-demo', ['--force' => true])->assertFailed();

    config(['bakery.demo.password' => 'a-long-demo-secret-2026']);
    $this->artisan('bakery:seed-demo')->assertFailed();

    expect(Branch::query()->count())->toBe(0);
})->group('feature', 'seeds');

it('seeds production with --force and BAKERY_DEMO_PASSWORD: the accounts use that password and its short codes', function () {
    app()->detectEnvironment(fn (): string => 'production');
    config(['bakery.demo.password' => 'a-long-demo-secret-2026']);

    $this->artisan('bakery:seed-demo', ['--force' => true])
        ->doesntExpectOutputToContain('a-long-demo-secret-2026')
        ->assertSuccessful();

    $branch = Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->sole();
    $this->postJson('/api/v1/login', ['phone' => DemoBakery::ADMIN_PHONE, 'password' => 'a-long-demo-secret-2026'])->assertOk();

    $barista = collect(DemoBakery::PEOPLE)->firstWhere('role', 'barista');
    $code = DemoCredentials::shortCodes(array_column(DemoBakery::PEOPLE, 'phone'), 'a-long-demo-secret-2026')[$barista['phone']];
    expect(app(ShortCodes::class)->verify($branch->id, $code, 'seeds-command-pad')->phone)->toBe($barista['phone']);
})->group('feature', 'seeds');

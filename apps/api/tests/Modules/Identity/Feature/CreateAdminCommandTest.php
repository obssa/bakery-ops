<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchScope;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

/*
 * `bakery:create-admin {phone} {--name=} {--branch=} {--password=}`: the console way to get the first admin of a
 * branch (CI's e2e job, the isolated "E2E Test Branch" on the live site, a fresh install). It creates the branch when
 * no branch has that name, seeds the role matrix when it is missing, and prints the password only when it generated
 * one. It never touches an existing user: a phone already in the branch is an error, not a password reset.
 */

function adminsOf(Branch $branch): array
{
    return User::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $branch->id)->get()->all();
}

it('creates the named branch and an admin in it who can sign in with the given password', function () {
    $this->artisan('bakery:create-admin', [
        'phone' => '+251911500001',
        '--name' => 'E2E Admin',
        '--branch' => 'E2E Test Branch',
        '--password' => 'e2e-secret-pass',
    ])->doesntExpectOutputToContain('e2e-secret-pass')->assertSuccessful();

    $branch = Branch::query()->where('name', 'E2E Test Branch')->sole();
    $users = adminsOf($branch);
    expect($users)->toHaveCount(1);

    /** @var User $admin */
    $admin = $users[0];
    expect($admin->name)->toBe('E2E Admin')
        ->and($admin->phone)->toBe('+251911500001')
        ->and($admin->locale)->toBe('en')
        ->and($admin->is_active)->toBeTrue()
        ->and($admin->hasRole('admin'))->toBeTrue();

    $login = $this->postJson('/api/v1/login', ['phone' => '+251911500001', 'password' => 'e2e-secret-pass'])->assertOk();
    expect($login->json('user.branch_id'))->toBe($branch->id)
        ->and($login->json('permissions'))->toContain('catalog.manage', 'people.manage');
})->group('module:identity', 'feature');

it('generates a password when none is given and prints it once', function () {
    $exit = Artisan::call('bakery:create-admin', ['phone' => '+251911500002', '--branch' => 'Bole']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and(preg_match('/password:\s*(\S{16,})/i', $output, $m))->toBe(1);

    $this->postJson('/api/v1/login', ['phone' => '+251911500002', 'password' => $m[1]])->assertOk();
})->group('module:identity', 'feature');

it('reuses an existing branch with that name instead of creating a second one', function () {
    $existing = Branch::factory()->create(['name' => 'E2E Test Branch']);

    $this->artisan('bakery:create-admin', ['phone' => '+251911500003', '--branch' => 'E2E Test Branch', '--password' => 'long-enough-1'])
        ->assertSuccessful();

    expect(Branch::query()->where('name', 'E2E Test Branch')->count())->toBe(1)
        ->and(adminsOf($existing))->toHaveCount(1);
})->group('module:identity', 'feature');

it('uses the oldest branch when --branch is omitted, and creates "Main" on an empty install', function () {
    $this->artisan('bakery:create-admin', ['phone' => '+251911500004', '--password' => 'long-enough-1'])->assertSuccessful();
    $main = Branch::query()->sole();
    expect($main->name)->toBe('Main')->and(adminsOf($main))->toHaveCount(1);

    Branch::factory()->create(['name' => 'Later']);
    $this->artisan('bakery:create-admin', ['phone' => '+251911500005', '--password' => 'long-enough-1'])->assertSuccessful();
    expect(adminsOf($main))->toHaveCount(2);
})->group('module:identity', 'feature');

it('seeds the role matrix when the roles table is empty, so the admin has every admin permission', function () {
    expect(Role::query()->count())->toBe(0);

    $this->artisan('bakery:create-admin', ['phone' => '+251911500006', '--branch' => 'Fresh', '--password' => 'long-enough-1'])
        ->assertSuccessful();

    expect(Role::query()->where('name', 'admin')->exists())->toBeTrue()
        ->and(Role::query()->count())->toBe(8);
})->group('module:identity', 'feature');

it('refuses a phone that already exists in the branch and leaves that user untouched', function () {
    seedRoles();
    $branch = Branch::factory()->create(['name' => 'Piazza']);
    $waiter = makeUser($branch, 'waiter', ['phone' => '+251911500007', 'password' => 'original-pass']);

    $this->artisan('bakery:create-admin', ['phone' => '+251911500007', '--branch' => 'Piazza', '--password' => 'another-pass-1'])
        ->assertFailed();

    $fresh = $waiter->fresh();
    expect($fresh?->hasRole('admin'))->toBeFalse()
        ->and(adminsOf($branch))->toHaveCount(1);
    $this->postJson('/api/v1/login', ['phone' => '+251911500007', 'password' => 'original-pass'])->assertOk();
})->group('module:identity', 'feature');

it('rejects a malformed phone or a short password and creates nothing', function (string $phone, string $password) {
    $this->artisan('bakery:create-admin', ['phone' => $phone, '--branch' => 'Nowhere', '--password' => $password])
        ->assertFailed();

    expect(Branch::query()->where('name', 'Nowhere')->exists())->toBeFalse()
        ->and(User::query()->withoutGlobalScope(BranchScope::class)->count())->toBe(0);
})->with([
    'letters in the phone' => ['09-ABC', 'long-enough-1'],
    'too short a phone' => ['12345', 'long-enough-1'],
    'short password' => ['+251911500008', 'short'],
])->group('module:identity', 'feature');

it('records the creation in the audit log of the new branch', function () {
    $this->artisan('bakery:create-admin', ['phone' => '+251911500009', '--branch' => 'Audited', '--password' => 'long-enough-1'])
        ->assertSuccessful();

    $branch = Branch::query()->where('name', 'Audited')->sole();
    $admin = adminsOf($branch)[0];
    $row = AuditLog::query()->where('action', 'user.create_admin')->sole();

    expect($row->branch_id)->toBe($branch->id)
        ->and($row->subject_id)->toBe($admin->id)
        ->and($row->actor_id)->toBe($admin->id);
})->group('module:identity', 'feature');

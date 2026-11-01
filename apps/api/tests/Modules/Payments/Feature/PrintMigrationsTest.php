<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PermissionRegistry;
use App\Shared\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PrintFixture;

/*
 * The three migrations of step 5.3a (phase-5 plan section 3), each safe on the live rows it touches:
 *   M3  2026_11_11_100300_create_print_jobs_table: a new table (no live rows); CHECKs on kind and status, client_uuid unique per branch.
 *   M2b 2026_11_11_100400_grant_phase_5_print_permissions: print.create and print.view (admin, manager, cashier) through the grant-only
 *       GrantRegistryPermissions, as every grant (never revokes, writes nothing the second time).
 *   M4  2026_11_11_100500_add_paper_width_to_receipt_settings: paper_width_mm (DEFAULT 80, CHECK 58 or 80) and print_logo (DEFAULT false)
 *       on receipt_settings, which has one row per branch on the live host: the defaults fill them, no row is updated.
 */

function printMigration(string $name): Migration
{
    /** @var Migration $migration */
    $migration = require database_path("migrations/{$name}.php");

    return $migration;
}

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->cashier = makeUser($this->branch, 'cashier');
    $this->order = PrintFixture::paidOrder($this->cashier);
});

it('creates print_jobs with the plan\'s columns', function () {
    expect(Schema::hasColumns('print_jobs', [
        'id', 'branch_id', 'client_uuid', 'order_id', 'kind', 'copies', 'status', 'attempts', 'claimed_by_device_id', 'claimed_at', 'printed_at',
        'failed_at', 'failure_key', 'created_by_user_id', 'acting_user_id', 'shift_instance_id', 'station_id', 'device_id', 'created_at',
        'updated_at', 'deleted_at',
    ]))->toBeTrue();
})->group('module:payments', 'feature');

it('refuses a status or a kind outside the lists, and a client_uuid used twice in a branch', function (array $state) {
    expect(fn () => PrintFixture::job($this->order, $this->cashier, $state))->toThrow(QueryException::class);
})->with([
    'status' => [['status' => 'lost']],
    'kind' => [['kind' => 'kitchen']],
])->group('module:payments', 'feature');

it('keeps a client_uuid unique within a branch only', function () {
    $uuid = (string) Str::uuid();
    PrintFixture::job($this->order, $this->cashier, ['client_uuid' => $uuid]);

    expect(fn () => PrintFixture::job($this->order, $this->cashier, ['client_uuid' => $uuid]))->toThrow(QueryException::class);
    $other = Branch::factory()->create();
    $theirCashier = makeUser($other, 'cashier');
    expect(PrintFixture::job(PrintFixture::paidOrder($theirCashier, 3), $theirCashier, ['client_uuid' => $uuid, 'branch_id' => $other->id]))->toBeInt();
})->group('module:payments', 'feature');

it('gives receipt_settings an 80 mm paper by default, refuses any other width than 58 or 80, and no logo', function () {
    DB::table('receipt_settings')->insert([
        'branch_id' => $this->branch->id, 'business_name_en' => 'Demo', 'business_name_am' => 'ማሳያ', 'show_amharic' => true, 'ethiopian_dates' => false,
        'created_by_user_id' => $this->cashier->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $row = DB::table('receipt_settings')->where('branch_id', $this->branch->id)->first();

    expect((int) $row->paper_width_mm)->toBe(80)->and((bool) $row->print_logo)->toBeFalse();
    DB::table('receipt_settings')->where('branch_id', $this->branch->id)->update(['paper_width_mm' => 58]);
    expect(fn () => DB::table('receipt_settings')->where('branch_id', $this->branch->id)->update(['paper_width_mm' => 70]))->toThrow(QueryException::class);
})->group('module:payments', 'feature');

it('grants print.create and print.view to the till roles, never revokes and writes nothing the second time', function () {
    Permission::query()->whereIn('name', ['print.create', 'print.view'])->get()->each->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $manager = makeUser($this->branch, 'manager');
    Role::findByName('waiter', 'web')->givePermissionTo(Permission::findOrCreate('print.view', 'web'));

    printMigration('2026_11_11_100400_grant_phase_5_print_permissions')->up();
    $grants = DB::table('role_has_permissions')->count();
    printMigration('2026_11_11_100400_grant_phase_5_print_permissions')->up();

    expect(DB::table('role_has_permissions')->count())->toBe($grants)
        ->and(PermissionRegistry::rolesFor('print.create'))->toBe(['admin', 'manager', 'cashier'])
        ->and(PermissionRegistry::rolesFor('print.view'))->toBe(['admin', 'manager', 'cashier'])
        ->and($manager->fresh()?->can('print.create'))->toBeTrue()
        ->and(Role::findByName('waiter', 'web')->hasPermissionTo('print.view'))->toBeTrue('a hand-made grant survives')
        ->and(Role::findByName('chef', 'web')->hasPermissionTo('print.create'))->toBeFalse();
})->group('module:payments', 'feature');

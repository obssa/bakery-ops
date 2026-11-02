<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PermissionRegistry;
use App\Shared\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Step 5.5c's two migrations (phase-5 plan section 3), each safe on the live rows:
 *   M6 2026_11_11_100700_extend_notification_types_for_ops: the notifications.type CHECK gains print_failed, backup_failed, backup_stale and
 *      drill_failed, as 2026_11_10_100100 did for order_refunded: DDL only, every existing line keeps a type the new list holds (its down()
 *      removes the four types' lines first; DDL commits on MariaDB, so it is not run inside this transaction).
 *   M2c 2026_11_11_100800_grant_phase_5_ops_permissions: system.view for the admin through GrantRegistryPermissions (never revokes, writes
 *      nothing the second time).
 */

function opsMigration(string $name): Migration
{
    /** @var Migration $migration */
    $migration = require database_path("migrations/{$name}.php");

    return $migration;
}

/** @param array<string, mixed> $over */
function opsInboxRow(int $branchId, int $userId, array $over = []): array
{
    return $over + [
        'branch_id' => $branchId, 'user_id' => $userId, 'type' => 'stock_low', 'severity' => 'warning', 'title_en' => 'T', 'title_am' => 'ቲ',
        'body_en' => 'B', 'body_am' => 'ቢ', 'payload' => '{}', 'deep_link' => '/', 'dedupe_key' => (string) Str::uuid(), 'read_at' => null,
        'created_at' => now(),
    ];
}

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
});

it('lets the inbox hold the four ops types after M6, keeps every older line, and refuses an unknown type', function () {
    DB::table('notifications')->insert(opsInboxRow($this->branch->id, $this->admin->id, ['type' => 'order_refunded']));
    foreach (['print_failed', 'backup_failed', 'backup_stale', 'drill_failed'] as $type) {
        DB::table('notifications')->insert(opsInboxRow($this->branch->id, $this->admin->id, ['type' => $type]));
    }

    expect(DB::table('notifications')->count())->toBe(5)
        ->and(fn () => DB::table('notifications')->insert(opsInboxRow($this->branch->id, $this->admin->id, ['type' => 'backup_ok'])))->toThrow(QueryException::class);
})->group('module:notifications', 'feature');

it('grants system.view to the admin only, never revokes and writes nothing the second time (M2c)', function () {
    Permission::query()->where('name', 'system.view')->get()->each->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::findByName('manager', 'web')->givePermissionTo(Permission::findOrCreate('audit.view', 'web'));

    opsMigration('2026_11_11_100800_grant_phase_5_ops_permissions')->up();
    $grants = DB::table('role_has_permissions')->count();
    opsMigration('2026_11_11_100800_grant_phase_5_ops_permissions')->up();

    expect(DB::table('role_has_permissions')->count())->toBe($grants)
        ->and(PermissionRegistry::rolesFor('system.view'))->toBe(['admin'])
        ->and($this->admin->fresh()?->can('system.view'))->toBeTrue()
        ->and(Role::findByName('manager', 'web')->hasPermissionTo('system.view'))->toBeFalse()
        ->and(Role::findByName('manager', 'web')->hasPermissionTo('audit.view'))->toBeTrue('a grant is never taken away');
})->group('module:identity', 'feature');

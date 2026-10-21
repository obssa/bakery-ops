<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Shared\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

pest()->group('module:notifications', 'feature');

/*
 * Step 2.5b (phase-2 plan section 3, migrations 11 and 12): where a person can be reached outside the app (endpoints: a push
 * subscription or a Telegram chat, the address encrypted, one live row per person, driver and address) and what was sent
 * there (deliveries: one row per notification and endpoint, with its status and attempt count). The inbox line is the in_app
 * delivery and has no row here. The endpoints table comes first in the migration order because deliveries reference it.
 */

function driverColumn(string $table, string $column): ?object
{
    return DB::selectOne(
        'select data_type as data_type, character_maximum_length as len, is_nullable as nullable
           from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        [dbSchema(), $table, $column],
    );
}

/** @return array<string, mixed> a raw endpoint row for a fresh branch and user */
function endpointRow(array $over = []): array
{
    static $branch = null, $user = null;
    if (! ($over['_reuse'] ?? false) || $branch === null) {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
    }
    unset($over['_reuse']);

    return $over + [
        'branch_id' => $branch->id, 'user_id' => $user->id, 'driver' => 'push', 'address_hash' => hash('sha256', 'a'),
        'address' => 'encrypted', 'user_agent' => null, 'verified_at' => null, 'last_success_at' => null, 'revoked_at' => null,
        'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
    ];
}

it('creates notification_endpoints with branch_id, an encrypted address, a sha256 hash and soft deletes', function () {
    expect(Schema::hasTable('notification_endpoints'))->toBeTrue()
        ->and(strtoupper((string) driverColumn('notification_endpoints', 'branch_id')?->nullable))->toBe('NO')
        ->and(strtoupper((string) driverColumn('notification_endpoints', 'user_id')?->nullable))->toBe('NO')
        ->and((int) driverColumn('notification_endpoints', 'address_hash')?->len)->toBe(64)
        ->and(strtolower((string) driverColumn('notification_endpoints', 'address_hash')?->data_type))->toBe('char')
        ->and(in_array(strtolower((string) driverColumn('notification_endpoints', 'address')?->data_type), ['text', 'longtext'], true))->toBeTrue()
        ->and((int) driverColumn('notification_endpoints', 'user_agent')?->len)->toBe(255)
        ->and(Schema::hasColumns('notification_endpoints', ['verified_at', 'last_success_at', 'revoked_at', 'created_at', 'updated_at', 'deleted_at']))->toBeTrue();
});

it('accepts push, telegram and sms endpoints and refuses any other driver', function () {
    foreach (['push', 'telegram', 'sms'] as $i => $driver) {
        DB::table('notification_endpoints')->insert(endpointRow(['_reuse' => true, 'driver' => $driver, 'address_hash' => hash('sha256', "d{$i}")]));
    }

    expect(DB::table('notification_endpoints')->count())->toBe(3)
        ->and(fn () => DB::table('notification_endpoints')->insert(endpointRow(['_reuse' => true, 'driver' => 'email'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('notification_endpoints')->insert(endpointRow(['_reuse' => true, 'driver' => 'in_app'])))->toThrow(QueryException::class);
});

it('refuses a second row for the same person, driver and address, but allows the same address for another person or driver', function () {
    DB::table('notification_endpoints')->insert(endpointRow());

    expect(fn () => DB::table('notification_endpoints')->insert(endpointRow(['_reuse' => true])))->toThrow(QueryException::class);
    DB::table('notification_endpoints')->insert(endpointRow(['_reuse' => true, 'driver' => 'telegram']));
    $other = User::factory()->create(['branch_id' => DB::table('notification_endpoints')->value('branch_id')]);
    DB::table('notification_endpoints')->insert(endpointRow(['_reuse' => true, 'user_id' => $other->id]));

    expect(DB::table('notification_endpoints')->count())->toBe(3);
});

it('creates notification_deliveries with a status, an attempt count, the error and no soft delete', function () {
    expect(Schema::hasTable('notification_deliveries'))->toBeTrue()
        ->and(strtoupper((string) driverColumn('notification_deliveries', 'branch_id')?->nullable))->toBe('NO')
        ->and(strtoupper((string) driverColumn('notification_deliveries', 'endpoint_id')?->nullable))->toBe('NO')
        ->and(strtoupper((string) driverColumn('notification_deliveries', 'last_error')?->nullable))->toBe('YES')
        ->and((int) driverColumn('notification_deliveries', 'last_error')?->len)->toBe(255)
        ->and(strtoupper((string) driverColumn('notification_deliveries', 'sent_at')?->nullable))->toBe('YES')
        ->and(Schema::hasColumns('notification_deliveries', ['notification_id', 'driver', 'status', 'attempts', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumn('notification_deliveries', 'deleted_at'))->toBeFalse('history: a delivery is never deleted');
});

it('accepts the four statuses and three drivers of a delivery and refuses any other', function () {
    $line = Notification::factory()->create();
    $endpoint = NotificationEndpoint::factory()->create(['branch_id' => $line->branch_id, 'user_id' => $line->user_id]);
    $row = fn (array $over = []): array => $over + [
        'branch_id' => $line->branch_id, 'notification_id' => $line->id, 'driver' => 'push', 'endpoint_id' => $endpoint->id,
        'status' => 'pending', 'attempts' => 0, 'last_error' => null, 'sent_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ];
    foreach (['pending', 'sent', 'failed', 'gone'] as $status) {
        DB::table('notification_deliveries')->insert($row(['status' => $status]));
    }
    foreach (['telegram', 'sms'] as $driver) {
        DB::table('notification_deliveries')->insert($row(['driver' => $driver]));
    }

    expect(DB::table('notification_deliveries')->count())->toBe(6)
        ->and(fn () => DB::table('notification_deliveries')->insert($row(['status' => 'queued'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('notification_deliveries')->insert($row(['driver' => 'in_app'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('notification_deliveries')->insert($row(['attempts' => -1])))->toThrow(QueryException::class);
});

it('has models scoped to the current branch, with the address encrypted at rest and decrypted on read', function () {
    seedRoles();
    $mine = actInBranch(Branch::factory()->create());
    $theirs = Branch::factory()->create();
    $me = makeUser($mine, 'waiter');
    $stranger = makeUser($theirs, 'waiter');

    $endpoint = NotificationEndpoint::factory()->telegram()->create(['branch_id' => $mine->id, 'user_id' => $me->id, 'address' => '4815162342']);
    NotificationEndpoint::factory()->create(['branch_id' => $theirs->id, 'user_id' => $stranger->id]);
    $line = Notification::factory()->create(['branch_id' => $mine->id, 'user_id' => $me->id]);
    $delivery = NotificationDelivery::factory()->create(['branch_id' => $mine->id, 'notification_id' => $line->id, 'endpoint_id' => $endpoint->id, 'driver' => 'telegram']);
    $other = Notification::factory()->create(['branch_id' => $theirs->id, 'user_id' => $stranger->id]);
    NotificationDelivery::factory()->create([
        'branch_id' => $theirs->id, 'notification_id' => $other->id, 'driver' => 'push',
        'endpoint_id' => NotificationEndpoint::query()->withoutGlobalScopes()->where('branch_id', $theirs->id)->value('id'),
    ]);

    $raw = DB::table('notification_endpoints')->where('id', $endpoint->id)->value('address');
    expect(NotificationEndpoint::query()->count())->toBe(1)
        ->and(NotificationDelivery::query()->count())->toBe(1)
        ->and($raw)->not->toContain('4815162342')
        ->and($endpoint->fresh()->address)->toBe('4815162342')
        ->and($endpoint->fresh()->address_hash)->toBe(hash('sha256', '4815162342'))
        ->and($delivery->fresh()->attempts)->toBe(0)
        ->and($delivery->fresh()->status)->toBe('pending')
        ->and($delivery->notification->id)->toBe($line->id)
        ->and($delivery->endpoint->id)->toBe($endpoint->id);
    $endpoint->delete();
    expect(NotificationEndpoint::query()->count())->toBe(0)->and(NotificationEndpoint::withTrashed()->count())->toBe(1);
});

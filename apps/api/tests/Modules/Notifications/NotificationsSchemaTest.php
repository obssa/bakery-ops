<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDedupe;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Step 2.5a (phase-2 plan section 3, migrations 10 and 13): the inbox (one row per recipient per event) and the delivery
 * gate for episodic alerts. The inbox refuses a second row for the same user and event key at the database; the gate
 * allows one active row per (branch, key) and any number of cleared ones, with no partial index and no FOR UPDATE on a
 * missing row (NULLs are distinct in a unique index, the technique of stock_alert_states).
 */

function notificationColumn(string $table, string $column): ?object
{
    return DB::selectOne(
        'select data_type as data_type, character_maximum_length as len, is_nullable as nullable
           from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        [dbSchema(), $table, $column],
    );
}

/** @return array<string, mixed> a raw inbox row for a fresh branch and user; $over replaces any column */
function inboxRow(array $over = []): array
{
    static $branch = null, $user = null;
    if (! ($over['_reuse'] ?? false) || $branch === null) {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
    }
    unset($over['_reuse']);

    return $over + [
        'branch_id' => $branch->id, 'user_id' => $user->id, 'type' => 'stock_out', 'severity' => 'critical',
        'title_en' => 'Flour is out of stock', 'title_am' => 'ዱቄት አልቋል', 'body_en' => 'None left at the store.', 'body_am' => 'በመጋዘኑ ምንም አልቀረም።',
        'payload' => json_encode(['item_id' => 1]), 'deep_link' => '/inventory?item=1', 'dedupe_key' => null,
        'read_at' => null, 'created_at' => now(),
    ];
}

/** @return array<string, mixed> a raw gate row for a fresh branch; $over replaces any column */
function gateRow(array $over = []): array
{
    static $branch = null;
    if (! ($over['_reuse'] ?? false) || $branch === null) {
        $branch = Branch::factory()->create();
    }
    unset($over['_reuse']);

    return $over + [
        'branch_id' => $branch->id, 'dedupe_key' => 'stock_out:1:2', 'type' => 'stock_out', 'is_active' => 1,
        'active_since' => now(), 'cleared_at' => null,
    ];
}

it('creates notifications with branch_id NOT NULL, both languages, a JSON payload and microsecond created_at', function () {
    expect(Schema::hasTable('notifications'))->toBeTrue()
        ->and(strtoupper((string) notificationColumn('notifications', 'branch_id')?->nullable))->toBe('NO')
        ->and(strtoupper((string) notificationColumn('notifications', 'user_id')?->nullable))->toBe('NO')
        ->and(strtoupper((string) notificationColumn('notifications', 'read_at')?->nullable))->toBe('YES')
        ->and(strtoupper((string) notificationColumn('notifications', 'dedupe_key')?->nullable))->toBe('YES');
    foreach (['title_en', 'title_am', 'body_en', 'body_am', 'payload', 'deep_link', 'created_at', 'severity', 'type'] as $column) {
        expect(notificationColumn('notifications', $column))->not->toBeNull($column);
    }
    expect((int) notificationColumn('notifications', 'title_en')?->len)->toBe(160)
        ->and((int) notificationColumn('notifications', 'body_en')?->len)->toBe(1000, 'step 4.4d: the weekly summary carries its PDF link')
        ->and((int) notificationColumn('notifications', 'body_am')?->len)->toBe(1000)
        ->and((int) notificationColumn('notifications', 'dedupe_key')?->len)->toBe(120)
        ->and(Schema::hasColumn('notifications', 'deleted_at'))->toBeFalse('history: the inbox is never deleted');
})->group('module:notifications', 'feature');

it('accepts the fifteen types and three severities of the plan (migration 11 added the three of Phase 3, step 4.3c payment_disputed, step 4.4d weekly_summary) and refuses any other', function () {
    $types = ['stock_low', 'stock_out', 'production_requested', 'production_assigned', 'batch_pending_confirmation', 'batch_done', 'transfer_requested', 'transfer_decided', 'line_ready', 'line_rejected', 'count_submitted', 'variance_decided', 'chain_broken', 'payment_disputed', 'weekly_summary'];
    foreach ($types as $type) {
        DB::table('notifications')->insert(inboxRow(['_reuse' => true, 'type' => $type]));
    }
    foreach (['info', 'warning', 'critical'] as $severity) {
        DB::table('notifications')->insert(inboxRow(['_reuse' => true, 'severity' => $severity]));
    }

    expect(DB::table('notifications')->count())->toBe(18)
        ->and(fn () => DB::table('notifications')->insert(inboxRow(['_reuse' => true, 'type' => 'shift_opened'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('notifications')->insert(inboxRow(['_reuse' => true, 'severity' => 'fatal'])))->toThrow(QueryException::class);
})->group('module:notifications', 'feature');

it('refuses a second inbox row for the same user and event key, but allows many without a key and the same key for another user', function () {
    DB::table('notifications')->insert(inboxRow(['dedupe_key' => 'line_ready:7']));

    expect(fn () => DB::table('notifications')->insert(inboxRow(['_reuse' => true, 'dedupe_key' => 'line_ready:7'])))->toThrow(QueryException::class);
    DB::table('notifications')->insert(inboxRow(['_reuse' => true, 'dedupe_key' => null]));
    DB::table('notifications')->insert(inboxRow(['_reuse' => true, 'dedupe_key' => null]));
    $other = User::factory()->create(['branch_id' => DB::table('notifications')->value('branch_id')]);
    DB::table('notifications')->insert(inboxRow(['_reuse' => true, 'dedupe_key' => 'line_ready:7', 'user_id' => $other->id]));

    expect(DB::table('notifications')->count())->toBe(4);
})->group('module:notifications', 'feature');

it('creates notification_dedupe with at most one active row per branch and key, and any number of cleared ones', function () {
    expect(Schema::hasTable('notification_dedupe'))->toBeTrue()
        ->and(strtoupper((string) notificationColumn('notification_dedupe', 'branch_id')?->nullable))->toBe('NO')
        ->and(strtoupper((string) notificationColumn('notification_dedupe', 'is_active')?->nullable))->toBe('YES', 'NULL once cleared');

    DB::table('notification_dedupe')->insert(gateRow());
    expect(fn () => DB::table('notification_dedupe')->insert(gateRow(['_reuse' => true])))->toThrow(QueryException::class);
    foreach ([1, 2, 3] as $n) {
        DB::table('notification_dedupe')->insert(gateRow(['_reuse' => true, 'is_active' => null, 'cleared_at' => now()]));
    }
    DB::table('notification_dedupe')->insert(gateRow(['_reuse' => true, 'dedupe_key' => 'stock_out:1:3']));

    expect(DB::table('notification_dedupe')->count())->toBe(5)
        ->and(DB::table('notification_dedupe')->where('is_active', 1)->count())->toBe(2)
        ->and(fn () => DB::table('notification_dedupe')->insert(gateRow(['_reuse' => true, 'is_active' => 2, 'dedupe_key' => 'other'])))->toThrow(QueryException::class);
})->group('module:notifications', 'feature');

it('keeps one key apart per branch', function () {
    DB::table('notification_dedupe')->insert(gateRow());
    DB::table('notification_dedupe')->insert(gateRow(['branch_id' => Branch::factory()->create()->id]));

    expect(DB::table('notification_dedupe')->where('dedupe_key', 'stock_out:1:2')->where('is_active', 1)->count())->toBe(2);
})->group('module:notifications', 'feature');

it('has models that are scoped to the current branch, cast what they hold, and a factory', function () {
    seedRoles();
    $mine = actInBranch(Branch::factory()->create());
    $theirs = Branch::factory()->create();
    $me = makeUser($mine, 'waiter');
    $stranger = makeUser($theirs, 'waiter');

    $row = Notification::factory()->create(['branch_id' => $mine->id, 'user_id' => $me->id, 'payload' => ['item_id' => 4, 'available' => '0.0000']]);
    Notification::factory()->create(['branch_id' => $theirs->id, 'user_id' => $stranger->id]);
    NotificationDedupe::query()->forceCreate(['branch_id' => $mine->id, 'dedupe_key' => 'stock_out:4:5', 'type' => 'stock_out', 'is_active' => 1, 'active_since' => now()]);
    NotificationDedupe::query()->forceCreate(['branch_id' => $theirs->id, 'dedupe_key' => 'stock_out:4:5', 'type' => 'stock_out', 'is_active' => 1, 'active_since' => now()]);

    $found = Notification::query()->get();
    expect($found->pluck('id')->all())->toBe([$row->id])
        ->and($found[0]->payload)->toBe(['item_id' => 4, 'available' => '0.0000'])
        ->and($found[0]->read_at)->toBeNull()
        ->and($found[0]->created_at->toIso8601String())->toBeString()
        ->and(NotificationDedupe::query()->count())->toBe(1);
    app(CurrentBranch::class)->clear();
    expect(Notification::query()->withoutGlobalScopes()->count())->toBe(2);
})->group('module:notifications', 'feature');

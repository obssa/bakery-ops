<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Services\ProductPhotos;
use App\Modules\Identity\Models\Device;
use App\Ops\Backup\BackupFolder;
use App\Ops\Backup\Manifest;
use App\Ops\Models\OpsRun;
use App\Ops\Status\SystemStatus;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * GET /system/status (step 5.5c, phase-5 plan 4.4; system.view, the admin): what the System page shows, as one conditional read.
 *   {app: {commit, released_at, migrations}, scheduler: {last_tick_at, ok}, queue: {pending, oldest_age_s, failed_24h, failed_total},
 *    backups: {last_ok_at, last_status, bytes, age_h, kept, disk_used_mb, drill: {last_at, status}}, files: {used_mb, media_used_mb},
 *    devices: {enrolled, seen_15m, revoked}, flags: {...}, error_tracking: {enabled}}
 * files.media_used_mb (step 6.1b) is what the live product photos take, from item_images.bytes. Never a path, a key, a password or a host name. The scheduler writes `ops.scheduler.tick` in the cache every minute; the heartbeat is ok
 * when it is at most 3 minutes old. The ETag is taken over the body, so an unchanged status answers 304 and a new backup a new tag.
 */

uses()->group('module:shared', 'feature');

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));
    $this->folder = sys_get_temp_dir().'/status-backups-'.Str::lower(Str::random(10));
    mkdir($this->folder, 0700, true);
    config(['backup.path' => $this->folder]);
    $this->status = function (array $headers = []) {
        loginAs($this->admin);

        return $this->getJson('/api/v1/system/status', $headers);
    };
});

afterEach(function () {
    BackupFolder::removeTree($this->folder);
});

/** One ops_runs row as App\Ops writes it. */
function statusRun(string $kind, string $status, string $started, ?string $finished, array $over = []): OpsRun
{
    return OpsRun::query()->create($over + [
        'kind' => $kind,
        'status' => $status,
        'started_at' => CarbonImmutable::parse($started, 'UTC'),
        'finished_at' => $finished === null ? null : CarbonImmutable::parse($finished, 'UTC'),
    ]);
}

/** A good dump of $mib MiB in the backup folder, with its manifest. */
function statusDump(string $folder, string $stamp, int $mib): void
{
    $name = BackupFolder::PREFIX.$stamp.'.sql.gz';
    file_put_contents($folder.'/'.$name, str_repeat('x', $mib * 1048576));
    (new Manifest($name, str_repeat('a', 64), $mib * 1048576, '2026-11-20T02:30:00Z', null, 120, [], [], [], []))
        ->write((new BackupFolder($folder))->manifestPath($name));
}

it('answers the admin with the whole shape of plan 4.4', function () {
    ($this->status)()->assertOk()->assertJsonStructure([
        'app' => ['commit', 'released_at', 'migrations'],
        'scheduler' => ['last_tick_at', 'ok'],
        'queue' => ['pending', 'oldest_age_s', 'failed_24h', 'failed_total'],
        'backups' => ['last_ok_at', 'last_status', 'bytes', 'age_h', 'kept', 'disk_used_mb', 'drill' => ['last_at', 'status']],
        'files' => ['used_mb', 'media_used_mb'],
        'devices' => ['enrolled', 'seen_15m', 'revoked'],
        'flags' => ['require_enrolled_device', 'require_shift_for_cash', 'require_shift_for_station_locations', 'open_ad_hoc_on_accept'],
        'error_tracking' => ['enabled'],
    ]);
});

it('refuses everyone without system.view', function () {
    foreach (['manager', 'cashier', 'waiter'] as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson('/api/v1/system/status')->assertForbidden();
    }
});

it('reports the backups and the drill from ops_runs, and the dumps the folder keeps', function () {
    statusRun('backup', 'ok', '2026-11-19 02:30:00', '2026-11-19 02:31:00', ['bytes' => 1048576]);
    statusRun('backup', 'ok', '2026-11-20 02:30:00', '2026-11-20 02:32:00', ['bytes' => 3145728, 'path_hint' => 'bakery-ops-20261120-023000Z.sql.gz']);
    statusRun('backup', 'failed', '2026-11-20 05:00:00', '2026-11-20 05:00:10', ['detail' => ['failure_key' => 'backup.dump_failed']]);
    statusRun('drill', 'skipped', '2026-11-15 03:30:00', '2026-11-15 03:30:01', ['detail' => ['failure_key' => 'drill.not_configured']]);
    statusDump($this->folder, '20261119-023000Z', 1);
    statusDump($this->folder, '20261120-023000Z', 3);

    $backups = ($this->status)()->assertOk()->json('backups');

    expect($backups)->toBe([
        'last_ok_at' => '2026-11-20T02:32:00Z',
        'last_status' => 'failed',
        'bytes' => 3145728,
        'age_h' => 6,
        'kept' => 2,
        'disk_used_mb' => 4,
        'drill' => ['last_at' => '2026-11-15T03:30:01Z', 'status' => 'skipped'],
    ]);
});

it('says plainly that nothing has run yet: no backup, no drill, an empty folder', function () {
    expect(($this->status)()->assertOk()->json('backups'))->toBe([
        'last_ok_at' => null,
        'last_status' => null,
        'bytes' => null,
        'age_h' => null,
        'kept' => 0,
        'disk_used_mb' => 0,
        'drill' => ['last_at' => null, 'status' => null],
    ]);
});

it('reads a backup folder that does not exist yet without creating it', function () {
    BackupFolder::removeTree($this->folder);

    expect(($this->status)()->assertOk()->json('backups.kept'))->toBe(0)
        ->and(is_dir($this->folder))->toBeFalse('a read never creates the backup folder');
});

it('has a scheduler heartbeat written every minute, ok while it is at most 3 minutes old', function () {
    $tick = collect(app(Schedule::class)->events())->first(fn ($e): bool => $e instanceof CallbackEvent && $e->description === 'ops.scheduler.tick');
    expect($tick)->not->toBeNull('the schedule has the ops.scheduler.tick callback')
        ->and($tick->expression)->toBe('* * * * *');

    expect(($this->status)()->json('scheduler'))->toBe(['last_tick_at' => null, 'ok' => false]);

    $tick->run(app());
    expect(Cache::get('ops.scheduler.tick'))->toBe('2026-11-20T09:00:00Z')
        ->and(($this->status)()->json('scheduler'))->toBe(['last_tick_at' => '2026-11-20T09:00:00Z', 'ok' => true]);

    $this->travel(3)->minutes();
    expect(($this->status)()->json('scheduler.ok'))->toBeTrue();
    $this->travel(1)->minutes();
    expect(($this->status)()->json('scheduler.ok'))->toBeFalse('a tick older than 3 minutes means the scheduler stopped');
});

it('counts the queue: what waits, how old the oldest job is, and the failed jobs of the last day and in all', function () {
    config(['queue.default' => 'database']);
    $now = CarbonImmutable::now()->getTimestamp();
    $job = fn (int $age): array => ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now - $age, 'created_at' => $now - $age];
    DB::table('jobs')->insert([$job(30), $job(150)]);
    $failed = fn (int $hours): array => ['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'RuntimeException', 'failed_at' => now()->subHours($hours)];
    DB::table('failed_jobs')->insert([$failed(1), $failed(23), $failed(30), $failed(400)]);

    expect(($this->status)()->json('queue'))->toBe(['pending' => 2, 'oldest_age_s' => 150, 'failed_24h' => 2, 'failed_total' => 4]);

    DB::table('jobs')->delete();
    expect(($this->status)()->json('queue.oldest_age_s'))->toBeNull();
});

it('counts the devices of the branch: enrolled, seen in the last 15 minutes, revoked', function () {
    Device::factory()->create(['branch_id' => $this->branch->id, 'last_seen_at' => now()->subMinutes(5)]);
    Device::factory()->create(['branch_id' => $this->branch->id, 'last_seen_at' => now()->subMinutes(20)]);
    Device::factory()->create(['branch_id' => $this->branch->id, 'last_seen_at' => null]);
    Device::factory()->revoked()->create(['branch_id' => $this->branch->id, 'last_seen_at' => now()->subMinutes(1)]);
    Device::factory()->create(['branch_id' => Branch::factory()->create()->id, 'last_seen_at' => now()->subMinutes(1)]);

    expect(($this->status)()->json('devices'))->toBe(['enrolled' => 3, 'seen_15m' => 1, 'revoked' => 1]);
});

it('adds up the stored files of the host in whole MiB, without the deleted ones (one disk holds every branch\'s photos)', function () {
    $file = fn (int $branchId, int $bytes, array $over = []): array => $over + [
        'branch_id' => $branchId, 'client_uuid' => (string) Str::uuid(), 'purpose' => 'receipt', 'disk' => 'files',
        'key' => 'receipts/'.$branchId.'/2026/11/'.Str::uuid().'.jpg', 'mime' => 'image/jpeg', 'bytes' => $bytes, 'width' => 800, 'height' => 600,
        'sha256' => str_repeat('b', 64), 'uploaded_by_user_id' => null, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
    ];
    DB::table('stored_files')->insert([
        $file($this->branch->id, 3 * 1048576),
        $file(Branch::factory()->create()->id, 1048576 + 600000),
        $file($this->branch->id, 50 * 1048576, ['deleted_at' => now()]),
    ]);

    expect(($this->status)()->json('files.used_mb'))->toBe(5);
});

it('adds up the live product photos of the host in whole MiB: every branch, a photo two products share once, never a removed one', function () {
    $other = Branch::factory()->create();
    $item = fn (Branch $branch): int => Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $branch->id])->id;
    $photo = fn (Branch $branch, string $sha, int $bytes, array $over = []): array => $over + [
        'branch_id' => $branch->id, 'item_id' => $item($branch), 'sha256' => $sha, 'width' => 1600, 'height' => 1200, 'mime' => 'image/webp',
        'bytes' => $bytes, 'disk' => 'media', 'key_prefix' => 'items/'.substr($sha, 0, 2).'/'.$sha, 'origin' => 'upload',
        'uploaded_by_user_id' => null, 'client_uuid' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
    ];
    expect(($this->status)()->json('files.media_used_mb'))->toBe(0, 'no photo yet');

    DB::table('item_images')->insert([
        $photo($this->branch, str_repeat('1', 64), 3 * 1048576),
        $photo($this->branch, str_repeat('1', 64), 3 * 1048576), // a second product with the same photo: the same files
        $photo($other, str_repeat('2', 64), 1048576 + 600000),
        $photo($this->branch, str_repeat('3', 64), 50 * 1048576, ['deleted_at' => now()]), // replaced or removed: not shown any more
    ]);

    expect(($this->status)()->json('files'))->toBe(['used_mb' => 0, 'media_used_mb' => 5]);
});

it('shows the rollout flags and whether error tracking is on, never the DSN', function () {
    config([
        'identity.require_enrolled_device' => true,
        'payments.require_shift_for_cash' => false,
        'ledger.require_shift_for_station_locations' => true,
        'shifts.open_ad_hoc_on_accept' => true,
    ]);
    $r = ($this->status)()->assertOk();
    expect($r->json('flags'))->toMatchArray([
        'require_enrolled_device' => true,
        'require_shift_for_cash' => false,
        'require_shift_for_station_locations' => true,
        'open_ad_hoc_on_accept' => true,
    ])->and($r->json('error_tracking'))->toBe(['enabled' => false]);

    config(['sentry.dsn' => 'https://0123456789abcdef0123456789abcdef@o42.ingest.sentry.example.test/4242']);
    $r = ($this->status)()->assertOk();
    expect($r->json('error_tracking'))->toBe(['enabled' => true])
        ->and($r->getContent())->not->toContain('0123456789abcdef')->not->toContain('sentry.example.test');
});

it('never puts a path, a key, a password or a host name in the body', function () {
    statusRun('backup', 'ok', '2026-11-20 02:30:00', '2026-11-20 02:32:00', [
        'bytes' => 1048576, 'sha256' => str_repeat('c', 64), 'path_hint' => 'bakery-ops-20261120-023000Z.sql.gz',
        'detail' => ['rows' => ['orders' => 3]],
    ]);
    statusRun('backup', 'failed', '2026-11-20 05:00:00', '2026-11-20 05:00:10', ['detail' => ['failure_key' => 'backup.dump_failed']]);
    statusDump($this->folder, '20261120-023000Z', 1);
    Storage::fake(config('catalog.images.disk'));
    config(['catalog.images.format' => 'jpeg']);
    withActor($this->admin->id);
    $photo = app(ProductPhotos::class)->set(Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]), imgJpeg(1200, 900), null, (string) Str::uuid());
    config([
        'sentry.dsn' => 'https://feedfacefeedfacefeedfacefeedface@o7.ingest.sentry.example.test/77',
        'backup.drill_database' => 'bakery_drill_scratch',
        'backup.remote' => 'offsite-remote:bakery',
        'notifications.drivers.telegram.bot_token' => '123456789:telegram-token-value',
        'app.url' => 'https://status-host.example.test',
    ]);

    $body = (string) ($this->status)()->assertOk()->getContent();
    $connection = (array) config('database.connections.'.config('database.default'));
    $forbidden = array_filter([
        $this->folder, sys_get_temp_dir(), base_path(), storage_path(), 'bakery-ops-20261120', str_repeat('c', 64), str_repeat('a', 64),
        'feedfacefeed', 'sentry.example.test', 'bakery_drill_scratch', 'offsite-remote', 'telegram-token-value', 'status-host.example.test',
        (string) ($connection['host'] ?? ''), (string) ($connection['password'] ?? ''), (string) ($connection['database'] ?? ''),
        (string) ($connection['username'] ?? ''), (string) config('app.key'), '/home/', '/tmp/', '/var/',
        $photo->sha256, $photo->key_prefix, 'items/', 'media/', '"media"',
    ], fn (string $s): bool => strlen($s) >= 3);

    foreach ($forbidden as $needle) {
        expect(str_contains($body, $needle))->toBeFalse("the status body holds '{$needle}'");
    }
});

it('is a conditional read: the same status answers 304 with no body, a new backup a new tag', function () {
    statusRun('backup', 'ok', '2026-11-20 02:30:00', '2026-11-20 02:32:00', ['bytes' => 1048576]);

    $first = ($this->status)()->assertOk();
    $etag = (string) $first->headers->get('ETag');
    expect($etag)->toStartWith('"system.status-')
        ->and($first->headers->get('Cache-Control'))->toContain('no-cache')->toContain('private');

    $again = ($this->status)(['If-None-Match' => $etag]);
    $again->assertStatus(304);
    expect($again->getContent())->toBe('')
        ->and(($this->status)(['If-None-Match' => 'W/'.$etag])->status())->toBe(304);

    statusRun('backup', 'ok', '2026-11-20 08:30:00', '2026-11-20 08:32:00', ['bytes' => 2097152]);
    $changed = ($this->status)(['If-None-Match' => $etag])->assertOk();
    expect($changed->headers->get('ETag'))->not->toBe($etag)
        ->and($changed->json('backups.bytes'))->toBe(2097152);
});

it('names the release from the REVISION file the deploy writes: its commit and when, and the migrations that ran', function () {
    $revision = $this->folder.'/REVISION';
    app()->when(SystemStatus::class)->needs('$revisionFile')->give($revision);
    file_put_contents($revision, "0123abcd4567ef89\n");
    touch($revision, CarbonImmutable::parse('2026-11-19 22:10:00', 'UTC')->getTimestamp());

    expect(($this->status)()->json('app'))->toBe([
        'commit' => '0123abcd4567ef89',
        'released_at' => '2026-11-19T22:10:00Z',
        'migrations' => DB::table('migrations')->count(),
    ]);
});

it('names no commit outside a release, nor from a REVISION that holds anything but a commit', function () {
    $revision = $this->folder.'/REVISION';
    app()->when(SystemStatus::class)->needs('$revisionFile')->give($revision);
    $none = ['commit' => null, 'released_at' => null, 'migrations' => DB::table('migrations')->count()];

    expect(($this->status)()->json('app'))->toBe($none);

    file_put_contents($revision, "release of the night, see /home/somebody\n");
    expect(($this->status)()->json('app'))->toBe($none);
});

<?php

declare(strict_types=1);

use App\Ops\Backup\BackupFolder;
use App\Ops\Backup\Manifest;
use App\Ops\Models\OpsRun;
use App\Shared\Events\BackupFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\OpsFixture;

/*
 * `backup:run` (phase-5 plan 5.5b and section 7; ADR 0020) against the test server with the real mysqldump of the API image (the MariaDB
 * 10.11 client the live host has): the rows are committed (CommitsForConcurrency) so the dump's own connection sees them. The backup
 * folder, the files folder and the media folder (the product photos, step 6.1b) are temporary folders outside the app (OpsFixture). Only
 * the backup-drill CI job runs this group.
 */

pest()->group('ops');

uses(CommitsForConcurrency::class);

beforeEach(function () {
    $this->ops = new OpsFixture;
});

afterEach(function () {
    $this->ops->tearDown();
});

it('writes a gzipped dump, its manifest, the files folder and the media folder as hard links, and records an ok run without a path', function () {
    $this->ops->ledger();
    file_put_contents($this->ops->files.'/receipts/photo.jpg', 'a receipt photo');
    $sha = str_repeat('ab', 32);
    mkdir($this->ops->media.'/items/ab/'.$sha, 0700, true);
    foreach (['thumb', 'card', 'large'] as $rendition) {
        file_put_contents($this->ops->media.'/items/ab/'.$sha.'/'.$rendition.'.webp', "a product photo, {$rendition}");
    }

    expect(Artisan::call('backup:run'))->toBe(0, Artisan::output());

    $run = OpsRun::query()->where('kind', 'backup')->sole();
    $name = (string) $run->path_hint;
    $dump = $this->ops->folder.'/'.$name;
    expect($run->status)->toBe('ok')
        ->and($name)->toMatch('/^bakery-ops-\d{8}-\d{6}Z\.sql\.gz$/')
        ->and($run->sha256)->toBe(hash_file('sha256', $dump))
        ->and($run->bytes)->toBe(filesize($dump))
        ->and($run->finished_at)->not->toBeNull();

    $sql = implode('', gzfile($dump) ?: []);
    expect($sql)->toContain('CREATE TABLE `stock_movements`')
        ->toContain('stock_movements_forbid_update_delete_delete')
        ->toContain('item_stock_view')
        ->toContain('-- Dump completed');

    $manifest = Manifest::read($this->ops->folder.'/'.OpsFixture::base($name).'.json');
    expect($manifest)->not->toBeNull()
        ->and($manifest->dump)->toBe($name)
        ->and($manifest->sha256)->toBe($run->sha256)
        ->and($manifest->bytes)->toBe($run->bytes)
        ->and($manifest->migrations)->toBe(DB::table('migrations')->count())
        ->and($manifest->rows['stock_movements'])->toBe(DB::table('stock_movements')->count())
        ->and($manifest->rows['stock_movements'])->toBeGreaterThan(0)
        ->and($manifest->triggers)->toContain('stock_movements_forbid_update_delete_update', 'audit_log_forbid_update_delete_delete')
        ->and($manifest->files)->toMatchArray(['status' => 'ok', 'count' => 1, 'linked' => 1, 'copied' => 0])
        ->and($manifest->media)->toMatchArray(['status' => 'ok', 'count' => 3, 'linked' => 3, 'copied' => 0])
        ->and($manifest->media['bytes'])->toBe(strlen('a product photo, thumb') + strlen('a product photo, card') + strlen('a product photo, large'))
        ->and($run->detail['media'] ?? null)->toBe($manifest->media);

    $snapshot = $this->ops->folder.'/'.OpsFixture::base($name).'.files/receipts/photo.jpg';
    expect(fileinode($snapshot))->toBe(fileinode($this->ops->files.'/receipts/photo.jpg'), 'a hard link, not a copy');
    $photo = $this->ops->folder.'/'.OpsFixture::base($name).'.media/items/ab/'.$sha.'/card.webp';
    expect(fileinode($photo))->toBe(fileinode($this->ops->media.'/items/ab/'.$sha.'/card.webp'), 'the media folder beside the files folder, hard links too')
        ->and(is_dir($this->ops->folder.'/'.OpsFixture::base($name).'.files/items'))->toBeFalse('the two folders are kept apart');

    $stored = (string) json_encode([$run->detail, $run->path_hint]);
    expect($stored)->not->toContain($this->ops->folder)->not->toContain(sys_get_temp_dir().'/')
        ->and(glob($this->ops->folder.'/*.partial') ?: [])->toBe([]);
});

it('puts the password in a 0600 option file it deletes afterwards, never in the arguments', function () {
    $password = (string) DB::connection()->getConfig('password');
    expect($password)->not->toBe('', 'the test server has a password, so its absence from the arguments means something');
    $record = dirname($this->ops->folder).'/record';
    mkdir($record);
    $wrapper = $record.'/mysqldump';
    file_put_contents($wrapper, implode("\n", [
        '#!/bin/sh',
        "printf '%s\\n' \"\$@\" > {$record}/args",
        'option="${1#--defaults-extra-file=}"',
        "stat -c '%a' \"\$option\" > {$record}/mode",
        "printf '%s' \"\$option\" > {$record}/option",
        'exec mysqldump "$@"',
        '',
    ]));
    chmod($wrapper, 0755);
    config(['backup.binaries.mysqldump' => $wrapper]);

    expect(Artisan::call('backup:run'))->toBe(0, Artisan::output());

    $arguments = (string) file_get_contents($record.'/args');
    $option = (string) file_get_contents($record.'/option');
    expect($arguments)->not->toContain($password)
        ->and(strtok($arguments, "\n"))->toStartWith('--defaults-extra-file=')
        ->and($arguments)->toContain("--single-transaction\n--quick\n--routines\n--triggers\n--hex-blob\n--no-tablespaces\n--max-statement-time=0\n")
        ->and(trim((string) file_get_contents($record.'/mode')))->toBe('600')
        ->and($option)->toStartWith($this->ops->folder.'/')
        ->and(file_exists($option))->toBeFalse('the option file is deleted')
        ->and(glob($this->ops->folder.'/.client-*') ?: [])->toBe([])
        ->and(glob($this->ops->folder.'/.stderr-*') ?: [])->toBe([]);
});

it('fails with a key, raises BackupFailed, leaves nothing of the night and keeps the older dumps when mysqldump fails', function () {
    expect(Artisan::call('backup:run'))->toBe(0);
    $first = (string) OpsRun::query()->where('status', 'ok')->value('path_hint');
    $this->travel(1)->minutes();
    Event::fake([BackupFailed::class]);
    config(['backup.binaries.mysqldump' => '/bin/false']);

    expect(Artisan::call('backup:run'))->toBe(1);

    $failed = OpsRun::query()->where('status', 'failed')->sole();
    expect($failed->detail)->toMatchArray(['failure_key' => 'backup.dump_failed', 'exit_code' => 1])
        ->and($failed->path_hint)->toBeNull()
        ->and(glob($this->ops->folder.'/bakery-ops-*') ?: [])->toHaveCount(4, 'the first night only: dump, manifest, files folder and media folder')
        ->and(file_exists($this->ops->folder.'/'.$first))->toBeTrue();
    Event::assertDispatched(BackupFailed::class, fn (BackupFailed $e): bool => $e->runId === $failed->id && $e->failureKey === 'backup.dump_failed'
        && $e->kind === 'backup' && $e->ageH === 0);
});

it('refuses a folder inside the app, before creating anything there', function () {
    $inside = base_path('storage/backups-test-'.bin2hex(random_bytes(3)));
    config(['backup.path' => $inside]);

    expect(Artisan::call('backup:run'))->toBe(1)
        ->and(OpsRun::query()->sole()->detail)->toBe(['failure_key' => 'backup.path_inside_app'])
        ->and(is_dir($inside))->toBeFalse();
});

it('keeps 30 dumps by default and BACKUP_KEEP when set: the oldest go with their manifests and files, a dump without a manifest goes at once', function () {
    mkdir($this->ops->folder, 0700, true);
    $old = ['bakery-ops-20260101-003000Z.sql.gz', 'bakery-ops-20260102-003000Z.sql.gz', 'bakery-ops-20260103-003000Z.sql.gz'];
    foreach ($old as $name) {
        file_put_contents($this->ops->folder.'/'.$name, 'x');
        (new Manifest($name, hash('sha256', 'x'), 1, '2026-01-01T00:30:00Z', null, 1, [], [], [], []))->write($this->ops->folder.'/'.OpsFixture::base($name).'.json');
        mkdir($this->ops->folder.'/'.OpsFixture::base($name).'.files');
        mkdir($this->ops->folder.'/'.OpsFixture::base($name).'.media');
    }
    file_put_contents($this->ops->folder.'/bakery-ops-20260104-003000Z.sql.gz', 'no manifest');
    config(['backup.keep' => 2]);

    expect(Artisan::call('backup:run'))->toBe(0, Artisan::output());

    $new = (string) OpsRun::query()->where('status', 'ok')->value('path_hint');
    $left = array_map('basename', glob($this->ops->folder.'/bakery-ops-*.sql.gz') ?: []);
    sort($left);
    expect($left)->toBe([$old[2], $new])
        ->and(file_exists($this->ops->folder.'/'.OpsFixture::base($old[0]).'.json'))->toBeFalse()
        ->and(is_dir($this->ops->folder.'/'.OpsFixture::base($old[0]).'.files'))->toBeFalse()
        ->and(is_dir($this->ops->folder.'/'.OpsFixture::base($old[0]).'.media'))->toBeFalse('the media folder goes with its night')
        ->and(is_dir($this->ops->folder.'/'.OpsFixture::base($old[2]).'.media'))->toBeTrue()
        ->and(OpsRun::query()->where('status', 'ok')->sole()->detail)->toMatchArray(['kept' => 2, 'deleted' => 3, 'over_cap' => false]);
});

it('copies nothing off site while BACKUP_REMOTE is unset, and the dump, manifest, files and media through rclone when it is set', function () {
    Process::fake();
    expect(Artisan::call('backup:run'))->toBe(0)
        ->and(OpsRun::query()->where('kind', 'offsite')->count())->toBe(0);
    Process::assertNothingRan();

    $this->travel(1)->minutes();
    config(['backup.remote' => 'offsite:bakery-ops']);
    expect(Artisan::call('backup:run'))->toBe(0);

    $name = (string) OpsRun::query()->where('kind', 'backup')->orderByDesc('id')->value('path_hint');
    $offsite = OpsRun::query()->where('kind', 'offsite')->sole();
    expect($offsite->status)->toBe('ok')
        ->and($offsite->path_hint)->toBe($name)
        ->and(OpsRun::query()->where('kind', 'backup')->orderByDesc('id')->value('detail'))->toMatchArray(['offsite' => 'ok']);
    Process::assertRan(fn (PendingProcess $p): bool => $p->command === ['rclone', 'copyto', $this->ops->folder.'/'.$name, "offsite:bakery-ops/{$name}"]);
    Process::assertRan(fn (PendingProcess $p): bool => is_array($p->command) && $p->command[1] === 'copy' && $p->command[3] === 'offsite:bakery-ops/'.OpsFixture::base($name).'.files');
    Process::assertRan(fn (PendingProcess $p): bool => is_array($p->command) && $p->command[1] === 'copy' && $p->command[3] === 'offsite:bakery-ops/'.OpsFixture::base($name).'.media');
    expect($offsite->detail)->toBe(['copies' => 4]);
});

it('snapshots an empty media folder on a host with no product photo yet, and says not_local for a media disk that is not a folder', function () {
    BackupFolder::removeTree($this->ops->media);

    expect(Artisan::call('backup:run'))->toBe(0, Artisan::output());
    $name = (string) OpsRun::query()->where('status', 'ok')->value('path_hint');
    $manifest = Manifest::read($this->ops->folder.'/'.OpsFixture::base($name).'.json');
    expect($manifest?->media)->toBe(['status' => 'ok', 'count' => 0, 'bytes' => 0, 'linked' => 0, 'copied' => 0])
        ->and(is_dir($this->ops->folder.'/'.OpsFixture::base($name).'.media'))->toBeTrue();

    $this->travel(1)->minutes();
    config(['filesystems.disks.media' => ['driver' => 's3', 'bucket' => 'photos']]);
    expect(Artisan::call('backup:run'))->toBe(0, Artisan::output());
    $name = (string) OpsRun::query()->where('status', 'ok')->orderByDesc('id')->value('path_hint');
    expect(Manifest::read($this->ops->folder.'/'.OpsFixture::base($name).'.json')?->media)->toMatchArray(['status' => 'not_local', 'count' => 0])
        ->and(is_dir($this->ops->folder.'/'.OpsFixture::base($name).'.media'))->toBeFalse('a bucket keeps its own versions');
});

it('is scheduled every night at 02:30 Addis Ababa time and never twice at once', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'backup:run'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 2 * * *')
        ->and((string) $event->timezone)->toBe('Africa/Addis_Ababa')
        ->and($event->withoutOverlapping)->toBeTrue();
});

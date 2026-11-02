<?php

declare(strict_types=1);

use App\Ops\Models\OpsRun;
use App\Shared\Events\DrillFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ChainFixture;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\OpsFixture;
use Tests\Support\ScratchCopy;

/*
 * `backup:drill` (phase-5 plan 5.5b and section 7; ADR 0020) against the test server: a real dump of the committed test database, restored
 * with the real mysql client into the scratch database (SCRATCH_DB_DATABASE, default bakery_scratch, created by docker/mysql/init.sql),
 * then Inventory's LedgerOracle, Shifts' HashChain::verifyAll, the triggers and the row counts on it. Only the backup-drill CI job runs
 * this group, inside the API image (the MariaDB clients). Every test leaves the scratch database empty.
 */

pest()->group('ops');

uses(CommitsForConcurrency::class);

beforeEach(function () {
    $this->ops = new OpsFixture;
    $this->ops->emptyScratch();
    $this->drill = fn (): OpsRun => OpsRun::query()->where('kind', 'drill')->orderByDesc('id')->firstOrFail();
});

afterEach(function () {
    $this->ops->emptyScratch();
    $this->ops->tearDown();
});

it('records `skipped` and does nothing else while BACKUP_DRILL_DATABASE is unset', function () {
    config(['backup.drill_database' => null]);

    expect(Artisan::call('backup:drill'))->toBe(0)
        ->and(($this->drill)()->status)->toBe('skipped')
        ->and(($this->drill)()->detail)->toBe(['reason_key' => 'drill.not_configured']);
});

it('restores the newest good dump and passes the ledger, the chains, the triggers and the row counts, then empties the scratch database', function () {
    $this->ops->ledger();
    $this->ops->chain($this);
    expect(Artisan::call('backup:run'))->toBe(0, Artisan::output());

    expect(Artisan::call('backup:drill'))->toBe(0, Artisan::output());

    $run = ($this->drill)();
    expect($run->status)->toBe('ok')
        ->and($run->path_hint)->toBe(OpsRun::query()->where('kind', 'backup')->value('path_hint'))
        ->and($run->detail['ledger'])->toMatchArray(['mismatches' => 0])
        ->and($run->detail['ledger']['items'])->toBeGreaterThanOrEqual(2)
        ->and($run->detail['chains'])->toMatchArray(['places' => 1, 'links' => 2, 'failures' => 0, 'first_broken' => null])
        ->and($run->detail['triggers'])->toMatchArray(['missing' => [], 'silent' => []])
        ->and($run->detail['triggers']['probed'])->toBeGreaterThanOrEqual(1, 'stock_movements has rows to refuse a statement with')
        ->and($run->detail['rows']['off'])->toBe([])
        ->and($run->detail['kept'])->toBeFalse()
        ->and($this->ops->scratchTables())->toBe([], 'the scratch database is emptied');
});

it('leaves the restored copy in place with --keep, row for row', function () {
    $this->ops->ledger();
    expect(Artisan::call('backup:run'))->toBe(0);

    expect(Artisan::call('backup:drill', ['--keep' => true]))->toBe(0, Artisan::output());

    expect(($this->drill)()->detail['kept'])->toBeTrue()
        ->and($this->ops->scratchTables())->toContain('stock_movements', 'stock_balances', 'migrations')
        ->and(DB::connection('ops_probe')->table('stock_movements')->count())->toBe(DB::table('stock_movements')->count());
});

it('fails with drill.checksum_mismatch and raises DrillFailed when the dump was corrupted', function () {
    $this->ops->ledger();
    expect(Artisan::call('backup:run'))->toBe(0);
    $dump = $this->ops->folder.'/'.OpsRun::query()->where('kind', 'backup')->value('path_hint');
    $bytes = (string) file_get_contents($dump);
    $middle = intdiv(strlen($bytes), 2);
    file_put_contents($dump, substr($bytes, 0, $middle).chr(ord($bytes[$middle]) ^ 0xFF).substr($bytes, $middle + 1));
    Event::fake([DrillFailed::class]);

    expect(Artisan::call('backup:drill'))->toBe(1);

    $run = ($this->drill)();
    expect($run->status)->toBe('failed')
        ->and($run->detail)->toMatchArray(['failure_key' => 'drill.checksum_mismatch', 'failures' => ['drill.checksum_mismatch']])
        ->and($this->ops->scratchTables())->toBe([]);
    Event::assertDispatched(DrillFailed::class, fn (DrillFailed $e): bool => $e->runId === $run->id && $e->failureKey === 'drill.checksum_mismatch');
});

it('fails with drill.ledger_mismatch when a movement was deleted behind the triggers\' back before the backup', function () {
    $this->ops->ledger();
    $waste = (int) DB::table('stock_movements')->where('reason', 'waste')->value('id');
    ChainFixture::withoutTriggers(
        ['stock_movements_forbid_update_delete_update', 'stock_movements_forbid_update_delete_delete'],
        fn () => DB::statement('DELETE FROM stock_movements WHERE id = ?', [$waste]),
    );
    expect(Artisan::call('backup:run'))->toBe(0);
    Event::fake([DrillFailed::class]);

    expect(Artisan::call('backup:drill'))->toBe(1);

    $run = ($this->drill)();
    expect($run->detail['failures'])->toBe(['drill.ledger_mismatch'])
        ->and($run->detail['ledger']['mismatches'])->toBe(1)
        ->and($run->detail['ledger']['first'][0])->toMatchArray(['source' => 'stock_balances', 'movements' => ['on_hand' => '40.0000', 'reserved' => '0.0000'], 'recorded' => ['on_hand' => '37.5000', 'reserved' => '0.0000']]);
    Event::assertDispatched(DrillFailed::class, fn (DrillFailed $e): bool => $e->failureKey === 'drill.ledger_mismatch' && $e->mismatches === 1);
});

it('fails with drill.triggers_missing when the dump lacks an append-only table\'s triggers', function () {
    $this->ops->ledger();
    ChainFixture::withoutTriggers(
        ['stock_movements_forbid_update_delete_update', 'stock_movements_forbid_update_delete_delete'],
        fn () => expect(Artisan::call('backup:run'))->toBe(0),
    );

    expect(Artisan::call('backup:drill'))->toBe(1);

    expect(($this->drill)()->detail['failures'])->toBe(['drill.triggers_missing'])
        ->and(($this->drill)()->detail['triggers']['missing'])->toBe(['stock_movements:UPDATE', 'stock_movements:DELETE']);
});

it('refuses to restore into the app\'s own database, and fails with drill.no_dump when there is no good dump', function () {
    config(['backup.drill_database' => (string) DB::connection()->getDatabaseName()]);
    expect(Artisan::call('backup:drill'))->toBe(1)
        ->and(($this->drill)()->detail['failure_key'])->toBe('drill.same_database')
        ->and(Schema::hasTable('stock_movements'))->toBeTrue();

    config(['backup.drill_database' => ScratchCopy::database()]);
    expect(Artisan::call('backup:drill'))->toBe(1)
        ->and(($this->drill)()->detail['failure_key'])->toBe('drill.no_dump');
});

it('is scheduled on Sundays at 03:30 Addis Ababa time and never twice at once', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'backup:drill'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 3 * * 0')
        ->and((string) $event->timezone)->toBe('Africa/Addis_Ababa')
        ->and($event->withoutOverlapping)->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Shared\Live\OverlapCursor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/*
 * The server side of the overlap window (ADR 0009 decision 4; traps.md, live updates). Auto-increment ids become
 * visible in commit order, not in id order: id 3 can commit after id 4 has already been served. The cursor handed back
 * is therefore the last seen id held back by the window (the highest id among rows at least `window` seconds old),
 * every row after it is sent again on the next poll, and clients de-duplicate by id (LiveMergeTest). A late commit is
 * delivered instead of being jumped over. Step 1.5 runs the real feeds (order_events) through this class.
 *
 * The probe table is TEMPORARY: MariaDB creates and drops temporary tables without the implicit commit an ordinary
 * CREATE TABLE makes, so RefreshDatabase's wrapping transaction survives.
 */

beforeEach(function () {
    DB::statement('DROP TEMPORARY TABLE IF EXISTS live_cursor_probe');
    DB::statement('CREATE TEMPORARY TABLE live_cursor_probe (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, created_at DATETIME(6) NOT NULL)');
    $this->now = CarbonImmutable::parse('2026-10-21 09:00:00', 'UTC');
    CarbonImmutable::setTestNow($this->now);
    $this->insert = function (int $id, int $secondsAgo): void {
        DB::table('live_cursor_probe')->insert(['id' => $id, 'created_at' => $this->now->subSeconds($secondsAgo)->format('Y-m-d H:i:s.u')]);
    };
    $this->events = fn (): Builder => DB::table('live_cursor_probe');
    $this->ids = fn (Builder $q): array => $q->pluck('id')->map(fn ($id): int => (int) $id)->all();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    DB::statement('DROP TEMPORARY TABLE IF EXISTS live_cursor_probe');
});

it('holds the cursor back by the overlap window: the highest id among rows at least 5 s old', function () {
    ($this->insert)(1, 60);
    ($this->insert)(2, 30);
    ($this->insert)(3, 5);
    ($this->insert)(4, 1);
    $cursor = new OverlapCursor;

    expect($cursor->windowSeconds())->toBe(5)
        ->and($cursor->next(($this->events)(), null))->toBe(3)
        ->and($cursor->next(($this->events)(), 1))->toBe(3);
})->group('module:shared', 'feature');

it('returns every row after the cursor, in id order, and all of them on the first load', function () {
    foreach ([3 => 50, 1 => 60, 2 => 55, 4 => 1] as $id => $ago) {
        ($this->insert)($id, $ago);
    }
    $cursor = new OverlapCursor;

    expect(($this->ids)($cursor->after(($this->events)(), 2)))->toBe([3, 4])
        ->and(($this->ids)($cursor->after(($this->events)(), null)))->toBe([1, 2, 3, 4])
        ->and(($this->ids)($cursor->after(($this->events)(), 4)))->toBe([]);
})->group('module:shared', 'feature');

it('delivers a row that commits late, below an id the client has already seen', function () {
    $cursor = new OverlapCursor;
    ($this->insert)(1, 60);
    ($this->insert)(2, 30);
    ($this->insert)(4, 1);                       // id 3 is taken by a transaction that has not committed yet

    $firstPoll = $cursor->after(($this->events)(), null);
    $handedBack = $cursor->next(($this->events)(), null);
    expect(($this->ids)($firstPoll))->toBe([1, 2, 4])
        ->and($handedBack)->toBe(2);             // not 4: 4 is younger than the window

    ($this->insert)(3, 2);                       // id 3 commits now, after 4 was served

    expect(($this->ids)($cursor->after(($this->events)(), $handedBack)))->toBe([3, 4])
        // a cursor at the highest id seen (4) would have jumped over it for good
        ->and(($this->ids)($cursor->after(($this->events)(), 4)))->toBe([]);
})->group('module:shared', 'feature');

it('advances once the rows are older than the window, never backwards and never past a young row', function () {
    $cursor = new OverlapCursor;
    ($this->insert)(1, 60);
    ($this->insert)(2, 3);
    ($this->insert)(3, 1);

    expect($cursor->next(($this->events)(), 1))->toBe(1);

    $later = $this->now->addSeconds(10);
    ($this->insert)(4, -9);                      // written 1 s before $later
    expect($cursor->next(($this->events)(), 1, $later))->toBe(3)
        ->and($cursor->next(($this->events)(), 50, $later))->toBe(50)   // a cursor never moves backwards
        ->and($cursor->next(($this->events)(), null, $later))->toBe(3);
})->group('module:shared', 'feature');

it('answers an empty feed with the cursor it was given, or zero', function () {
    $cursor = new OverlapCursor;

    expect($cursor->next(($this->events)(), null))->toBe(0)
        ->and($cursor->next(($this->events)(), 9))->toBe(9)
        ->and(($this->ids)($cursor->after(($this->events)(), null)))->toBe([]);
})->group('module:shared', 'feature');

it('fingerprints the feed so a late commit below the highest id still changes it', function () {
    $cursor = new OverlapCursor;
    ($this->insert)(1, 60);
    ($this->insert)(2, 30);
    ($this->insert)(4, 1);

    $before = $cursor->fingerprint(($this->events)(), 2);
    expect($cursor->fingerprint(($this->events)(), 2))->toBe($before);       // nothing changed: same fingerprint

    ($this->insert)(3, 2);                                                    // the highest id is still 4
    $afterLateCommit = $cursor->fingerprint(($this->events)(), 2);
    expect($afterLateCommit)->not->toBe($before);

    ($this->insert)(5, 0);
    expect($cursor->fingerprint(($this->events)(), 2))->not->toBe($afterLateCommit)
        ->and($cursor->fingerprint(($this->events)(), 2))->toBe(['since' => 2, 'next' => 2, 'max' => 5, 'pending' => 3]);
})->group('module:shared', 'feature');

it('takes an Eloquent query as well and keeps the scopes the caller put on it', function () {
    ($this->insert)(1, 60);
    ($this->insert)(2, 50);
    ($this->insert)(3, 40);
    $model = new class extends Model
    {
        protected $table = 'live_cursor_probe';

        public $timestamps = false;
    };
    $cursor = new OverlapCursor;

    $query = $model->newQuery()->where('id', '!=', 3);
    $rows = $cursor->after($query, 1);

    expect($rows)->toBeInstanceOf(EloquentBuilder::class)
        ->and($rows->pluck('id')->map(fn ($id): int => (int) $id)->all())->toBe([2])
        ->and($cursor->next($model->newQuery()->where('id', '!=', 3), null))->toBe(2);
})->group('module:shared', 'feature');

it('reads the window from config, 5 s by default', function () {
    expect(OverlapCursor::fromConfig()->windowSeconds())->toBe(5);

    config(['bakery.live.overlap_seconds' => 8]);
    expect(OverlapCursor::fromConfig()->windowSeconds())->toBe(8);
})->group('module:shared', 'feature');

<?php

declare(strict_types=1);

use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;
use Tests\Support\ShiftsFixture;

/*
 * Step 3.2b, group concurrency (phase-3 plan sections 4 and 7), through the whole stack in two processes on two connections. At
 * most one shift runs at a station, and the database decides it: the unique key (station_id, open_key) refuses the second insert.
 * No row is locked before it exists (traps.md "First-row race"), so two people pressing Start at the same moment never deadlock:
 *   - two people starting the same station: one 201, one 409 shifts.shift.already_open naming the shift that won;
 *   - one person's start sent twice with the same client_uuid (a lost response, an offline replay): one shift, 201 and 200;
 *   - two people starting a scheduled instance: one wins, the other is told a shift is running.
 * 30 rounds each; the engine's deadlock counter must not move. CommitsForConcurrency (DatabaseTruncation): the children must see
 * committed rows.
 */

uses(CommitsForConcurrency::class);

const START_RACE_ROUNDS = 30;

function startRaceDeadlocks(): int
{
    if (DB::getDriverName() === 'pgsql') {
        return (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
    }
    $row = DB::selectOne("show global status like 'Innodb_deadlocks'");

    return $row === null ? 0 : (int) $row->Value;
}

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    config(['cache.default' => 'database']); // the idempotency locks must be shared between the processes, as on the live host
    $this->settings = fn () => DB::statement(DB::getDriverName() === 'pgsql' ? "set lock_timeout = '10s'" : 'set session innodb_lock_wait_timeout = 10');
    $this->start = fn ($person, $station, array $body): Closure => function () use ($person, $station, $body): array {
        ($this->settings)();
        loginAs($person);
        $response = $this->withHeaders(idempotencyKey())->postJson("/api/v1/stations/{$station->id}/shifts/start", $body);

        return ['status' => $response->getStatusCode(), 'message_key' => $response->json('message_key'), 'shift_id' => $response->json('shift.id') ?? $response->json('shift_id')];
    };
    // the next round starts from a free station: the shift that won is cancelled, as an admin would
    $this->free = fn () => ShiftInstance::query()->where('open_key', 1)->update(['status' => 'cancelled', 'open_key' => null, 'cancelled_at' => now(), 'cancel_reason' => 'next round']);
    $this->statuses = fn (array $results): array => collect($results)->map(fn ($r) => ($r['ok'] ?? false) ? $r['value']['status'] : -1)->sort()->values()->all();
});

it('lets one of two people start a station and tells the other a shift is running (30 rounds)', function () {
    $deadlocksBefore = startRaceDeadlocks();
    $failures = [];
    foreach (range(1, START_RACE_ROUNDS) as $round) {
        $results = Forks::together([
            ($this->start)($this->f->cashier, $this->f->till, ['client_uuid' => (string) Str::uuid()]),
            ($this->start)($this->f->cashier2, $this->f->till, ['client_uuid' => (string) Str::uuid()]),
        ]);
        $running = ShiftInstance::query()->where('station_id', $this->f->till->id)->where('open_key', 1)->get();
        $winner = collect($results)->first(fn ($r) => ($r['value']['status'] ?? 0) === 201);
        $loser = collect($results)->first(fn ($r) => ($r['value']['status'] ?? 0) === 409);
        if (($this->statuses)($results) !== [201, 409] || $running->count() !== 1 || $winner === null || $loser === null
            || $loser['value']['message_key'] !== 'shifts.shift.already_open' || $loser['value']['shift_id'] !== $running->first()->id
            || ShiftRoster::query()->where('shift_instance_id', $running->first()->id)->count() !== 1
            || DB::table('audit_log')->where('action', 'shift.start')->where('subject_id', $running->first()->id)->count() !== 1) {
            $failures[] = "round {$round}: ".json_encode($results);
        }
        ($this->free)();
    }

    expect($failures)->toBe([])->and(startRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:shifts', 'feature');

it('writes one shift when the same start is sent twice with one client_uuid (30 rounds)', function () {
    $deadlocksBefore = startRaceDeadlocks();
    $failures = [];
    foreach (range(1, START_RACE_ROUNDS) as $round) {
        $body = ['client_uuid' => (string) Str::uuid()];
        $results = Forks::together([
            ($this->start)($this->f->cashier, $this->f->till, $body),
            ($this->start)($this->f->cashier, $this->f->till, $body),
        ]);
        $rows = ShiftInstance::query()->where('client_uuid', $body['client_uuid'])->get();
        $ids = collect($results)->map(fn ($r) => $r['value']['shift_id'] ?? null)->unique()->values()->all();
        // one process makes the shift (201); the other is told which one it is (200), whether it came before or after the commit
        if (($this->statuses)($results) !== [200, 201] || $rows->count() !== 1 || count($ids) !== 1) {
            $failures[] = "round {$round}: ".json_encode($results);
        }
        ($this->free)();
    }

    expect($failures)->toBe([])->and(startRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:shifts', 'feature');

it('lets one of two people start the scheduled instance and tells the other a shift is running (30 rounds)', function () {
    $deadlocksBefore = startRaceDeadlocks();
    $failures = [];
    $day = $this->f->template([$this->f->till]);
    foreach (range(1, START_RACE_ROUNDS) as $round) {
        // a window that holds the real clock now (the children do not travel in time)
        $scheduled = $this->f->scheduled($this->f->till, $day, now()->subDays($round)->toDateString(), [$this->f->cashier, $this->f->cashier2], [
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(6),
        ]);
        $results = Forks::together([
            ($this->start)($this->f->cashier, $this->f->till, ['client_uuid' => (string) Str::uuid(), 'shift_id' => $scheduled->id]),
            ($this->start)($this->f->cashier2, $this->f->till, ['client_uuid' => (string) Str::uuid(), 'shift_id' => $scheduled->id]),
        ]);
        $row = $scheduled->fresh();
        $loser = collect($results)->first(fn ($r) => ($r['value']['status'] ?? 0) === 409);
        $ok = ($this->statuses)($results) === [201, 409]
            && in_array($loser['value']['message_key'] ?? null, ['shifts.shift.already_open', 'shifts.shift.not_scheduled'], true)
            && $row->status->value === 'open'
            && ShiftInstance::query()->where('station_id', $this->f->till->id)->where('open_key', 1)->count() === 1
            && DB::table('audit_log')->where('action', 'shift.start')->where('subject_id', $scheduled->id)->count() === 1
            && ShiftRoster::query()->where('shift_instance_id', $scheduled->id)->whereNotNull('joined_at')->count() === 1;
        if (! $ok) {
            $failures[] = "round {$round}: ".json_encode($results).", status {$row->status->value}";
        }
        ($this->free)();
    }

    expect($failures)->toBe([])->and(startRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:shifts', 'feature');

<?php

declare(strict_types=1);

use App\Shared\Http\Middleware\Idempotency;
use App\Shared\Models\Branch;
use App\Shared\Models\IdempotencyKey;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * ADR 0028: `bakery:prune-idempotency` deletes the stored answers of the Idempotency middleware (ADR 0003) whose replay window ended more
 * than bakery.idempotency.prune_after_hours (24) ago, in every branch, in chunks of ids, never a row whose key a request holds right now,
 * and stops at a time budget so a large first run goes on the next night. Daily at 02:00 in the shop's time, before the backup of 02:30.
 */

uses()->group('module:shared', 'feature');

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 20:00:00'));
    $this->branch = Branch::factory()->create();
    $this->other = Branch::factory()->create();
    // a stored answer whose expires_at is $hours from now (negative: in the past), as the middleware writes it
    $this->row = function (float $hours, ?Branch $branch = null, int $user = 7, ?string $key = null): IdempotencyKey {
        static $n = 0;
        $n++;

        return IdempotencyKey::query()->create([
            'branch_id' => ($branch ?? $this->branch)->id,
            'user_id' => $user,
            'key' => $key ?? "key-{$n}",
            'request_hash' => str_repeat('a', 64),
            'response_status' => 201,
            'response_body' => ['kind' => 'json', 'data' => ['n' => $n]],
            'expires_at' => CarbonImmutable::now()->addMinutes((int) round($hours * 60)),
        ]);
    };
    $this->prune = function (array $options = []): array {
        $code = Artisan::call('bakery:prune-idempotency', $options);

        return [$code, Artisan::output()];
    };
});

it('deletes the answers expired more than 24 hours ago in every branch, and keeps the live ones and those expired since', function () {
    $old = [($this->row)(-24.5), ($this->row)(-200), ($this->row)(-30, $this->other)];
    $kept = [($this->row)(-23.5), ($this->row)(-1), ($this->row)(3), ($this->row)(24, $this->other)];

    [$code, $out] = ($this->prune)();

    expect($code)->toBe(0)
        ->and($out)->toContain('deleted 3.')
        ->and(IdempotencyKey::query()->pluck('id')->sort()->values()->all())->toBe(collect($kept)->pluck('id')->sort()->values()->all())
        ->and(IdempotencyKey::query()->whereIn('id', collect($old)->pluck('id'))->exists())->toBeFalse();
});

it('follows bakery.idempotency.prune_after_hours', function () {
    $two = ($this->row)(-2);
    $half = ($this->row)(-0.5);
    config(['bakery.idempotency.prune_after_hours' => 1]);

    expect(($this->prune)()[0])->toBe(0)
        ->and(IdempotencyKey::query()->whereKey($two->id)->exists())->toBeFalse()
        ->and(IdempotencyKey::query()->whereKey($half->id)->exists())->toBeTrue();
});

it('keeps a row whose key a request holds right now, and deletes it on the next run once the lock is free', function () {
    $busy = ($this->row)(-48, user: 9, key: 'in-flight');
    $free = ($this->row)(-48, user: 9, key: 'done');
    $lock = Cache::lock(Idempotency::lockName($this->branch->id, 9, 'in-flight'), 60);
    expect($lock->get())->toBeTrue();

    [$code, $out] = ($this->prune)();

    expect($code)->toBe(0)
        ->and($out)->toContain('deleted 1, 1 in flight kept.')
        ->and(IdempotencyKey::query()->whereKey($busy->id)->exists())->toBeTrue()
        ->and(IdempotencyKey::query()->whereKey($free->id)->exists())->toBeFalse()
        ->and(Cache::lock(Idempotency::lockName($this->branch->id, 9, 'done'), 1)->get())->toBeTrue('the run released the locks it took');

    $lock->release();
    ($this->prune)();
    expect(IdempotencyKey::query()->whereKey($busy->id)->exists())->toBeFalse();
});

it('deletes by primary key in chunks of --chunk ids, one SELECT and one DELETE each, never reading a response body', function () {
    foreach (range(1, 7) as $i) {
        ($this->row)(-30 - $i);
    }
    $young = ($this->row)(-1);
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        if (str_contains($query->sql, 'idempotency_keys')) {
            $statements[] = $query;
        }
    });

    [$code, $out] = ($this->prune)(['--chunk' => 3]);

    $deletes = array_values(array_filter($statements, fn (QueryExecuted $q): bool => str_starts_with(strtolower($q->sql), 'delete')));
    $selects = array_values(array_filter($statements, fn (QueryExecuted $q): bool => str_starts_with(strtolower($q->sql), 'select')));
    expect($code)->toBe(0)
        ->and($out)->toContain('deleted 7.')
        ->and($deletes)->toHaveCount(3)
        ->and(array_map(fn (QueryExecuted $q): int => count($q->bindings) - 1, $deletes))->toBe([3, 3, 1])
        ->and($deletes[0]->sql)->toContain('`id` in')
        ->and(collect($selects)->every(fn (QueryExecuted $q): bool => ! str_contains($q->sql, 'response_body') && ! str_contains($q->sql, '*')))->toBeTrue()
        ->and(IdempotencyKey::query()->pluck('id')->all())->toBe([$young->id]);
});

it('stops at the time budget after a chunk and goes on from there on the next run', function () {
    foreach (range(1, 5) as $i) {
        ($this->row)(-30 - $i);
    }

    [$code, $out] = ($this->prune)(['--chunk' => 2, '--budget' => 0]);
    expect($code)->toBe(0)
        ->and($out)->toContain('deleted 2, stopped at the time budget, the next run goes on.')
        ->and(IdempotencyKey::query()->count())->toBe(3);

    ($this->prune)(['--chunk' => 2, '--budget' => 0]);
    expect(IdempotencyKey::query()->count())->toBe(1);

    [, $out] = ($this->prune)(['--chunk' => 2, '--budget' => 0]);
    expect($out)->toContain('deleted 1.')
        ->and(IdempotencyKey::query()->count())->toBe(0);
});

it('refuses a chunk or a budget that is not a whole number in range', function () {
    ($this->row)(-48);
    foreach ([['--chunk' => 0], ['--chunk' => 1001], ['--chunk' => 'x'], ['--budget' => -1], ['--budget' => '1.5']] as $options) {
        expect(($this->prune)($options)[0])->toBe(2, json_encode($options));
    }
    expect(IdempotencyKey::query()->count())->toBe(1);
});

it('is scheduled daily at 02:00 Addis Ababa time, before the backup of 02:30, and never twice at once', function () {
    $events = collect(app(Schedule::class)->events());
    $prune = $events->first(fn ($e) => str_contains((string) $e->command, 'bakery:prune-idempotency'));
    $backup = $events->first(fn ($e) => str_contains((string) $e->command, 'backup:run'));

    expect($prune)->not->toBeNull()
        ->and($prune->expression)->toBe('0 2 * * *')
        ->and((string) $prune->timezone)->toBe('Africa/Addis_Ababa')
        ->and($prune->withoutOverlapping)->toBeTrue()
        ->and($backup->expression)->toBe('30 2 * * *')
        ->and((string) $backup->timezone)->toBe('Africa/Addis_Ababa');
});

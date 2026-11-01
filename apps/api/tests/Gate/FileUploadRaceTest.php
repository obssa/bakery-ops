<?php

declare(strict_types=1);

use App\Shared\Models\Branch;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;

/*
 * Two uploads of one receipt photo at the same moment (phase-5 plan section 7 and 10.4, FileUploadRaceTest: the Phase 4 gap, likelier with
 * step 5.2b's photo queue, where a tablet sends a kept photo again after a lost answer, or two tabs of one tablet send the same queued photo).
 * Each is a real POST /files through the whole HTTP stack in its own forked process, with the same client_uuid and its own Idempotency-Key, so
 * the idempotency middleware does not put one behind the other: the unique (branch_id, client_uuid) index decides. The second starts 0 to
 * 290 ms after the first (10 ms more each round), so the rounds sweep from two uploads that meet at the insert to one that finds the row:
 *   - one after the other: the second finds the stored file and answers it (200);
 *   - raced: both looked before either was stored; the second's insert loses on the unique index, its object is deleted and it answers the
 *     winner's file (200), its own transaction rolled back (so no second audit row).
 * Either way: one answer 201 and one 200, both naming the same file; one stored_files row, one object on the disk and one file.upload audit
 * row per round; and the engine detects no deadlock (Innodb_deadlocks, 30 rounds). CommitsForConcurrency: the children connect on their own
 * and must see the committed rows.
 */

uses(CommitsForConcurrency::class);

if (! function_exists('fileUploadRaceDeadlocks')) {
    function fileUploadRaceDeadlocks(): int
    {
        if (DB::getDriverName() === 'pgsql') {
            return (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
        }
        $row = DB::selectOne("show global status like 'Innodb_deadlocks'");

        return $row === null ? 0 : (int) $row->Value;
    }
}

beforeEach(function () {
    Storage::fake(config('files.disk'));
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->cashier = makeUser($this->branch, 'cashier');
    // one upload in a child: its answer, and whether a transaction of its own was rolled back (the trace of a lost race)
    $this->upload = fn (string $uuid, string $bytes, int $delayMs): Closure => function () use ($uuid, $bytes, $delayMs): array {
        $rolledBack = false;
        Event::listen(TransactionRolledBack::class, function () use (&$rolledBack): void {
            $rolledBack = true;
        });
        usleep($delayMs * 1000);
        loginAs($this->cashier);
        $file = UploadedFile::fake()->createWithContent('slip.jpg', $bytes);
        $response = $this->withHeaders(['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
            ->post('/api/v1/files', ['file' => $file, 'purpose' => 'receipt', 'client_uuid' => $uuid]);

        return ['status' => $response->getStatusCode(), 'id' => $response->json('id'), 'message_key' => $response->json('message_key'), 'raced' => $rolledBack];
    };
});

it('keeps one file for two uploads of one client_uuid at once: both answered with it, one row, one object, one audit row (30 rounds)', function () {
    $deadlocksBefore = fileUploadRaceDeadlocks();
    $failures = [];
    $seen = [];

    foreach (range(1, 30) as $round) {
        $uuid = (string) Str::uuid();
        $bytes = imgJpeg(800 + $round, 600);

        $results = Forks::together([
            ($this->upload)($uuid, $bytes, 0),
            ($this->upload)($uuid, $bytes, ($round - 1) * 10),
        ]);

        $answers = array_map(
            fn (array $r): array => ($r['ok'] ?? false) ? $r['value'] : ['status' => 'error '.($r['error'] ?? '?'), 'id' => null, 'message_key' => null, 'raced' => false],
            $results,
        );
        $statuses = array_column($answers, 'status');
        sort($statuses);
        $rows = DB::table('stored_files')->where('client_uuid', $uuid)->pluck('id')->all();
        $audits = DB::table('audit_log')->where('action', 'file.upload')->where('subject_type', 'stored_file')->whereIn('subject_id', $rows)->count();
        $sameFile = count($rows) === 1 && (int) $answers[0]['id'] === (int) $rows[0] && (int) $answers[1]['id'] === (int) $rows[0];
        if ($statuses !== [200, 201] || ! $sameFile || $audits !== 1) {
            $failures[] = "round {$round}: ".json_encode($answers).' rows '.json_encode($rows)." audits {$audits}";
        }
        $outcome = ($answers[0]['raced'] || $answers[1]['raced']) ? 'raced' : 'one after the other';
        $seen[$outcome] = ($seen[$outcome] ?? 0) + 1;
    }

    fwrite(STDERR, 'FileUploadRaceTest outcomes seen: '.json_encode($seen).PHP_EOL);
    expect($failures)->toBe([])
        ->and(DB::table('stored_files')->count())->toBe(30)
        ->and(Storage::disk(config('files.disk'))->allFiles())->toHaveCount(30, 'one object per photo: the loser deletes its own')
        ->and(fileUploadRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all')
        ->and(array_keys($seen))->toEqualCanonicalizing(['raced', 'one after the other'], 'both orders of the race happened');
})->group('concurrency', 'module:shared', 'feature', 'gate:p5');

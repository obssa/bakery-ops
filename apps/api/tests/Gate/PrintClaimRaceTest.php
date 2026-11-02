<?php

declare(strict_types=1);

use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;
use Tests\Support\PrintFixture;

/*
 * Two print bridges racing to claim one job (step 5.3a, phase-5 plan sections 4.3 and 7; group concurrency). Each claim runs in its own
 * process on its own connection through the whole HTTP stack, at the same moment. The claim locks the job's row, so exactly one bridge
 * gets 200 and the job, the other 409 payments.print.already_claimed; the job is claimed once (attempts 1, one audit row), and the engine
 * reports no deadlock over 30 rounds (the Innodb_deadlocks counter). CommitsForConcurrency: the children only see committed rows.
 */

uses(CommitsForConcurrency::class);

if (! function_exists('printRaceDeadlocks')) {
    function printRaceDeadlocks(): int
    {
        if (DB::getDriverName() === 'pgsql') {
            return (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
        }
        $row = DB::selectOne("show global status like 'Innodb_deadlocks'");

        return $row === null ? 0 : (int) $row->Value;
    }
}

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->cashier = makeUser($this->branch, 'cashier');
    [$this->first, $this->firstToken] = PrintFixture::bridge($this->branch);
    [$this->second, $this->secondToken] = PrintFixture::bridge($this->branch);
    $this->claimJob = fn (string $token, int $job): Closure => function () use ($token, $job): array {
        $response = $this->withHeaders(PrintFixture::auth($token))
            ->postJson("/api/v1/bridge/jobs/{$job}/claim", ['width' => 576, 'client_uuid' => (string) Str::uuid()]);

        return ['status' => $response->getStatusCode(), 'message_key' => $response->json('message_key')];
    };
});

it('lets exactly one of two bridges claim a job at the same moment (30 rounds)', function () {
    $deadlocksBefore = printRaceDeadlocks();
    $failures = [];
    foreach (range(1, 30) as $round) {
        $job = PrintFixture::job(PrintFixture::paidOrder($this->cashier, $round), $this->cashier);
        $results = Forks::together([($this->claimJob)($this->firstToken, $job), ($this->claimJob)($this->secondToken, $job)]);

        $statuses = array_map(fn (array $r): ?int => ($r['ok'] ?? false) === true ? ($r['value']['status'] ?? null) : null, $results);
        sort($statuses);
        if ($statuses !== [200, 409]) {
            $failures[] = "round {$round}: ".json_encode($results);

            continue;
        }
        $loser = $results[0]['value']['status'] === 409 ? $results[0]['value'] : $results[1]['value'];
        $winner = $results[0]['value']['status'] === 200 ? $this->first->id : $this->second->id;
        $row = PrintFixture::row($job);
        if ($loser['message_key'] !== 'payments.print.already_claimed' || $row->status !== 'claimed' || (int) $row->attempts !== 1
            || (int) $row->claimed_by_device_id !== $winner
            || DB::table('audit_log')->where('action', 'print_job.claim')->where('subject_id', $job)->count() !== 1) {
            $failures[] = "round {$round}: ".json_encode(['row' => $row, 'results' => $results]);
        }
    }

    expect($failures)->toBe([])->and(printRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:payments', 'feature');

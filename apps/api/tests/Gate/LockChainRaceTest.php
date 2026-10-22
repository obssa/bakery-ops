<?php

declare(strict_types=1);

use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Shifts\Contracts\HashChain;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Support\ChainFixture;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;
use Tests\Support\ShiftsFixture;

/*
 * Locking under real concurrency (step 3.4; phase-3 plan sections 7 and 10.3, group concurrency). Counts of one place lock at the same
 * moment, each in its own process on its own connection. The chain's sequence is claimed by the unique key (branch, location, chain_seq),
 * not by a head row (there is none, and a FOR UPDATE on a missing row is the first-row deadlock): the loser of a claim waits for the
 * winner's commit, gets a duplicate-key error, reads the new head with a locking read of a row that exists, and tries the next number.
 * So: sequences n and n+1 in order, every link's previous hash is the hash of the link before it, the chain verifies, and the engine
 * counts no deadlock (Innodb_deadlocks compared over about 30 rounds). Two of the jobs lock the first link of a place, the case a head
 * lock could not decide.
 */

uses(CommitsForConcurrency::class);

if (! function_exists('lockRaceDeadlocks')) {
    function lockRaceDeadlocks(): int
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
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->chain = new ChainFixture($this->f);
    $this->lockJob = fn (int $countId, int $by): Closure => function () use ($countId, $by): array {
        $count = StockCount::query()->findOrFail($countId);
        $locked = DB::transaction(fn (): StockCount => app(HashChain::class)->lock($count, $by));

        return ['chain_seq' => $locked->chain_seq, 'previous_hash' => $locked->previous_hash, 'payload_hash' => $locked->payload_hash];
    };
    $this->assertChain = function (int $placeId, int $links, string $label, array &$failures): void {
        $rows = StockCount::query()->where('location_id', $placeId)->whereNotNull('chain_seq')->orderBy('chain_seq')->get();
        $previous = str_repeat('0', 64);
        foreach ($rows as $i => $row) {
            if ($row->chain_seq !== $i + 1 || $row->previous_hash !== $previous) {
                $failures[] = "{$label}: link {$i} has seq {$row->chain_seq} and previous {$row->previous_hash}";
            }
            $previous = $row->payload_hash;
        }
        $result = app(HashChain::class)->verify($placeId)->toArray();
        if ($rows->count() !== $links || $result['ok'] !== true) {
            $failures[] = "{$label}: ".$rows->count()." links of {$links}, verify ".json_encode($result);
        }
    };
});

it('gives two counts of one place that lock at the same moment sequence n and n+1, 30 rounds, no deadlock', function () {
    $place = $this->f->place($this->f->kitchen);
    $deadlocksBefore = lockRaceDeadlocks();
    $failures = [];

    foreach (range(1, 30) as $round) {
        $a = $this->chain->approved($place);
        $b = $this->chain->approved($place);
        $results = Forks::together([($this->lockJob)($a->id, $this->f->admin->id), ($this->lockJob)($b->id, $this->f->manager->id)]);

        $seqs = [];
        foreach ($results as $i => $r) {
            if (($r['ok'] ?? false) !== true) {
                $failures[] = "round {$round}, job {$i}: ".json_encode($r);

                continue;
            }
            $seqs[] = $r['value']['chain_seq'];
        }
        sort($seqs);
        if ($seqs !== [2 * $round - 1, 2 * $round]) {
            $failures[] = "round {$round}: sequences ".json_encode($seqs);
        }
        ($this->assertChain)($place->id, 2 * $round, "round {$round}", $failures);
    }

    expect($failures)->toBe([])->and(lockRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:shifts', 'feature');

it('decides the first link of a new place by the unique key: two counts, 1 and 2, 30 rounds, no deadlock', function () {
    $deadlocksBefore = lockRaceDeadlocks();
    $failures = [];

    foreach (range(1, 30) as $round) {
        $place = $this->f->place($this->f->kitchen, $round % 2 === 0 ? LocationType::Kitchen : LocationType::Bar);
        $a = $this->chain->approved($place);
        $b = $this->chain->approved($place);
        $results = Forks::together([($this->lockJob)($a->id, $this->f->admin->id), ($this->lockJob)($b->id, $this->f->manager->id)]);

        $seqs = [];
        foreach ($results as $i => $r) {
            if (($r['ok'] ?? false) !== true) {
                $failures[] = "round {$round}, job {$i}: ".json_encode($r);

                continue;
            }
            $seqs[] = $r['value']['chain_seq'];
        }
        sort($seqs);
        if ($seqs !== [1, 2]) {
            $failures[] = "round {$round}: sequences ".json_encode($seqs);
        }
        ($this->assertChain)($place->id, 2, "round {$round}", $failures);
    }

    expect($failures)->toBe([])->and(lockRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:shifts', 'feature');

it('copes with three counts of a new place locking at once (10 rounds)', function () {
    $deadlocksBefore = lockRaceDeadlocks();
    $failures = [];

    foreach (range(1, 10) as $round) {
        $place = $this->f->place($this->f->kitchen);
        $ids = [$this->chain->approved($place)->id, $this->chain->approved($place)->id, $this->chain->approved($place)->id];
        $results = Forks::together(array_map(fn (int $id): Closure => ($this->lockJob)($id, $this->f->admin->id), $ids));

        $seqs = [];
        foreach ($results as $i => $r) {
            if (($r['ok'] ?? false) !== true) {
                $failures[] = "round {$round}, job {$i}: ".json_encode($r);

                continue;
            }
            $seqs[] = $r['value']['chain_seq'];
        }
        sort($seqs);
        if ($seqs !== [1, 2, 3]) {
            $failures[] = "round {$round}: sequences ".json_encode($seqs);
        }
        ($this->assertChain)($place->id, 3, "round {$round}", $failures);
    }

    expect($failures)->toBe([])->and(lockRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:shifts', 'feature');

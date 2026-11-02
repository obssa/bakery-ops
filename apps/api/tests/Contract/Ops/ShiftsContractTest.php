<?php

declare(strict_types=1);

use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Shifts\Contracts\HashChain;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\ChainFixture;
use Tests\Support\ScratchCopy;
use Tests\Support\ShiftsFixture;

/*
 * Contract: ops -> shifts (phase-5 plan section 2; manifest contracts["ops->shifts"], step 5.5b).
 *
 * Written BEFORE `HashChain::verifyAll` exists: RED in the first commit of 5.5b because the method is missing. The restore drill of
 * App\Ops consumes only
 *   HashChain::verifyAll(string $connection): ChainSummary   ->ok(), ->toArray() = {ok, places, links, first_broken, failures}
 * a method beside the per-place verify(): every place of every branch of the database behind the named connection, by the same rules
 * (gapless numbering, previous hash, sha256 of the stored bytes, the bytes the live rows rebuild). `places` is how many chains it
 * found, `links` how many links it checked, each failure is {branch_id, location_id, chain_seq, count_id, kind} ordered by branch,
 * place and link, and `first_broken` is the first of them (null when the chains are whole). It reads only that connection and puts
 * back the default connection and the current branch it found. The tamper case changes a locked count's stored hash on the scratch
 * copy (Tests\Support\ScratchCopy) behind the triggers' back; the default connection keeps the original.
 */

uses()->group('contract:ops->shifts');

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->chain = new ChainFixture($this->f);
    $this->place = $this->f->place($this->f->kitchen);
    $this->bar = $this->f->place($this->f->kitchen, LocationType::Bar);
    $this->links = $this->chain->chain($this->place, 3);
    $this->chain->chain($this->bar, 2);
    $this->verifyAll = fn (string $connection): array => app(HashChain::class)->verifyAll($connection)->toArray();
});

it('answers ok on seeded chains, on a copy and on the original, and counts the places and links it checked', function () {
    $scratch = ScratchCopy::ofDefault();

    expect(($this->verifyAll)($scratch))->toBe(['ok' => true, 'places' => 2, 'links' => 5, 'first_broken' => null, 'failures' => []])
        ->and(($this->verifyAll)((string) config('database.default')))->toBe(['ok' => true, 'places' => 2, 'links' => 5, 'first_broken' => null, 'failures' => []]);
});

it('checks every branch, and puts back the default connection and the current branch it found', function () {
    $other = Branch::factory()->create();
    $f2 = ShiftsFixture::build($other, $this);
    actInBranch($other);
    (new ChainFixture($f2))->chain($f2->place($f2->kitchen), 2);
    actInBranch($this->branch);
    $default = DB::getDefaultConnection();

    $result = ($this->verifyAll)(ScratchCopy::ofDefault());

    expect($result)->toBe(['ok' => true, 'places' => 3, 'links' => 7, 'first_broken' => null, 'failures' => []])
        ->and(DB::getDefaultConnection())->toBe($default)
        ->and(app(CurrentBranch::class)->id())->toBe($this->branch->id);
});

it('names the first broken link when a locked count\'s stored hash is changed on the copy, and the original stays whole', function () {
    $scratch = ScratchCopy::ofDefault();
    $second = $this->links[1];
    ScratchCopy::withoutTriggers('stock_counts', fn (Connection $db) => $db->statement(
        'UPDATE stock_counts SET payload_hash = ? WHERE id = ?',
        [str_repeat('a', 64), $second->id],
    ));

    $result = ($this->verifyAll)($scratch);

    $broken = ['branch_id' => $this->branch->id, 'location_id' => $this->place->id, 'chain_seq' => 2, 'count_id' => $second->id, 'kind' => 'hash_mismatch'];
    expect($result['ok'])->toBeFalse()
        ->and($result['places'])->toBe(2)
        ->and($result['links'])->toBe(5)
        ->and($result['first_broken'])->toBe($broken)
        // the next link still names the true hash of link 2 as its previous one
        ->and($result['failures'])->toBe([
            $broken,
            ['branch_id' => $this->branch->id, 'location_id' => $this->place->id, 'chain_seq' => 3, 'count_id' => $this->links[2]->id, 'kind' => 'previous_mismatch'],
        ])
        ->and(($this->verifyAll)((string) config('database.default'))['ok'])->toBeTrue('the original keeps its hash');
});

it('reads only the named connection and writes nothing: every query it runs is a SELECT on that connection', function () {
    $scratch = ScratchCopy::ofDefault();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = [$query->connectionName, $query->sql];
    });

    app(HashChain::class)->verifyAll($scratch);

    expect($queries)->not->toBeEmpty();
    foreach ($queries as [$connection, $sql]) {
        expect($connection)->toBe($scratch, "a query ran on {$connection}: {$sql}")
            ->and(strtolower(ltrim($sql)))->toStartWith('select');
    }
});

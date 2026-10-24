<?php

declare(strict_types=1);

use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Shifts\Contracts\HashChain;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use App\Shared\StateMachine\IllegalTransition;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ChainFixture;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature', 'gate:p3');

uses(CommitsForConcurrency::class);

/*
 * The hash chain (ADR 0012; phase-3 plan sections 3 and 10.3). Every locked count is a link of the chain of its place:
 * payload_hash = sha256(previous_hash . canonical_payload), the first link of a place has sixty-four zeros for a previous hash, chain_seq
 * runs 1, 2, 3 .. without gaps, and the stored bytes can be rebuilt from the live rows. verify() checks all of that and names the first
 * link that fails and how. The tamper cases play the person with write access to the database: they drop the protecting triggers
 * (ChainFixture::withoutTriggers; DDL commits, hence CommitsForConcurrency), change one thing and ask the chain.
 */

const COUNT_TRIGGERS = ['stock_counts_locked_forbid_update', 'stock_counts_forbid_delete'];
const LINE_TRIGGERS = ['stock_count_lines_freeze_update', 'stock_count_lines_forbid_delete'];

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->chain = new ChainFixture($this->f);
    $this->place = $this->f->place($this->f->kitchen);
    $this->bar = $this->f->place($this->f->kitchen, LocationType::Bar);
    $this->verify = fn (?int $location = null): array => app(HashChain::class)->verify($location ?? $this->place->id)->toArray();
});

it('locks an approved count as the first link: genesis previous hash, sequence 1, hash = sha256(previous + payload)', function () {
    $item = $this->f->ingredient();
    $count = $this->chain->link($this->place, [['item' => $item, 'counted' => '9500', 'expected' => '10000', 'reason' => 'spoilage']]);

    $row = StockCount::query()->findOrFail($count->id);
    expect($row->status)->toBe(CountStatus::Locked)
        ->and($row->chain_seq)->toBe(1)
        ->and($row->previous_hash)->toBe(str_repeat('0', 64))
        ->and($row->payload_version)->toBe(1)
        ->and($row->payload_hash)->toBe(hash('sha256', str_repeat('0', 64).$row->canonical_payload))
        ->and($row->payload_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($row->locked_at)->not->toBeNull()
        ->and($row->locked_by_user_id)->toBe($this->f->admin->id)
        ->and(json_decode($row->canonical_payload, true)['chain_seq'])->toBe(1)
        ->and($row->version)->toBe(2, 'the lock moves the version once');
});

it('chains the next link to the hash of the one before it, one place at a time', function () {
    $links = $this->chain->chain($this->place, 3);
    $other = $this->chain->chain($this->bar, 2);

    $rows = StockCount::query()->whereIn('id', array_map(fn ($l) => $l->id, $links))->orderBy('chain_seq')->get();
    expect($rows->pluck('chain_seq')->all())->toBe([1, 2, 3])
        ->and($rows[1]->previous_hash)->toBe($rows[0]->payload_hash)
        ->and($rows[2]->previous_hash)->toBe($rows[1]->payload_hash)
        ->and($rows[2]->payload_hash)->toBe(hash('sha256', $rows[2]->previous_hash.$rows[2]->canonical_payload));
    $otherRows = StockCount::query()->whereIn('id', array_map(fn ($l) => $l->id, $other))->orderBy('chain_seq')->get();
    expect($otherRows->pluck('chain_seq')->all())->toBe([1, 2], 'another place has a chain of its own')
        ->and($otherRows[0]->previous_hash)->toBe(str_repeat('0', 64));
});

it('keeps a chain per branch as well: the same place id cannot exist in two, but the sequence is per branch and place', function () {
    $this->chain->chain($this->place, 2);
    $branch2 = Branch::factory()->create();
    $f2 = ShiftsFixture::build($branch2, $this);
    actInBranch($branch2);
    $chain2 = new ChainFixture($f2);
    $place2 = $f2->place($f2->kitchen);
    $link = $chain2->chain($place2, 1)[0];

    expect(StockCount::query()->findOrFail($link->id)->chain_seq)->toBe(1)
        ->and(StockCount::query()->findOrFail($link->id)->previous_hash)->toBe(str_repeat('0', 64));
});

it('refuses to lock a count that is not approved, and leaves a locked one as it is', function () {
    $draft = StockCount::factory()->forShift(ShiftInstance::factory()->locked()->create(['branch_id' => $this->branch->id, 'station_id' => $this->f->kitchen->id, 'created_by_user_id' => $this->f->chef->id]), $this->place->id)->create(['created_by_user_id' => $this->f->chef->id]);
    expect(fn () => DB::transaction(fn () => app(HashChain::class)->lock($draft, $this->f->admin->id)))->toThrow(IllegalTransition::class);

    $locked = $this->chain->link($this->place);
    $again = DB::transaction(fn () => app(HashChain::class)->lock($locked, $this->f->manager->id));
    expect($again->chain_seq)->toBe(1)
        ->and($again->locked_by_user_id)->toBe($this->f->admin->id, 'a second lock changes nothing')
        ->and(StockCount::query()->where('chain_seq', '!=', null)->count())->toBe(1);
});

it('verifies a good chain: ok, how many links, the head hash', function () {
    $links = $this->chain->chain($this->place, 4);

    $result = ($this->verify)();

    $head = StockCount::query()->findOrFail($links[3]->id);
    expect($result)->toBe(['ok' => true, 'checked' => 4, 'head_hash' => $head->payload_hash, 'failures' => []]);
});

it('verifies a place with no links: ok, nothing checked, no head', function () {
    expect(($this->verify)())->toBe(['ok' => true, 'checked' => 0, 'head_hash' => null, 'failures' => []]);
});

it('exports every link with the bytes that were hashed, for the second implementation to recompute', function () {
    $links = $this->chain->chain($this->place, 2);

    $export = app(HashChain::class)->export($this->place->id);

    $second = StockCount::query()->findOrFail($links[1]->id);
    expect($export['format'])->toBe('bakery-chain-export')
        ->and($export['version'])->toBe(1)
        ->and($export['branch_id'])->toBe($this->branch->id)
        ->and($export['location_id'])->toBe($this->place->id)
        ->and($export['genesis_hash'])->toBe(str_repeat('0', 64))
        ->and($export['head_hash'])->toBe($second->payload_hash)
        ->and($export['links'])->toHaveCount(2)
        ->and($export['links'][1])->toBe([
            'chain_seq' => 2, 'count_id' => $second->id, 'previous_hash' => $second->previous_hash, 'payload_hash' => $second->payload_hash,
            'payload_version' => 1, 'canonical_payload' => $second->canonical_payload,
        ]);
});

/** @return array<string, array{0: Closure, 1: int, 2: string, 3: int}> the tamper, the chain_seq that is named, the kind, how many failures in all */
function tamperCases(): array
{
    return [
        'a line figure changed' => [function ($t) {
            ChainFixture::withoutTriggers(LINE_TRIGGERS, fn () => DB::table('stock_count_lines')->where('count_id', $t->links[1]->id)->update(['counted_qty' => '1.0000']));
        }, 2, 'payload_mismatch', 1],
        'a line reason note changed' => [function ($t) {
            ChainFixture::withoutTriggers(LINE_TRIGGERS, fn () => DB::table('stock_count_lines')->where('count_id', $t->links[2]->id)->update(['variance_note' => 'nothing happened']));
        }, 3, 'payload_mismatch', 1],
        'the decision note of a count changed' => [function ($t) {
            ChainFixture::withoutTriggers(COUNT_TRIGGERS, fn () => DB::table('stock_counts')->where('id', $t->links[1]->id)->update(['decision_note' => 'looked fine to me']));
        }, 2, 'payload_mismatch', 1],
        'who counted changed' => [function ($t) {
            ChainFixture::withoutTriggers(COUNT_TRIGGERS, fn () => DB::table('stock_counts')->where('id', $t->links[0]->id)->update(['counted_by_user_id' => $t->f->waiter->id]));
        }, 1, 'payload_mismatch', 1],
        'a cash figure written into the stored payload' => [function ($t) {
            $row = StockCount::query()->findOrFail($t->links[1]->id);
            $forged = str_replace('"cash":null', '"cash":{"counted_total":"1.00"}', $row->canonical_payload);
            ChainFixture::withoutTriggers(COUNT_TRIGGERS, fn () => DB::table('stock_counts')->where('id', $row->id)->update(['canonical_payload' => $forged]));
        }, 2, 'hash_mismatch', 1],
        'a payload rewritten with its hash recomputed to match' => [function ($t) {
            $row = StockCount::query()->findOrFail($t->links[1]->id);
            $forged = str_replace('"cash":null', '"cash":{"counted_total":"1.00"}', $row->canonical_payload);
            ChainFixture::withoutTriggers(COUNT_TRIGGERS, fn () => DB::table('stock_counts')->where('id', $row->id)->update(['canonical_payload' => $forged, 'payload_hash' => hash('sha256', $row->previous_hash.$forged)]));
        }, 2, 'payload_mismatch', 2],
        'a previous_hash changed' => [function ($t) {
            ChainFixture::withoutTriggers(COUNT_TRIGGERS, fn () => DB::table('stock_counts')->where('id', $t->links[1]->id)->update(['previous_hash' => str_repeat('a', 64)]));
        }, 2, 'previous_mismatch', 1],
        'a payload_hash changed' => [function ($t) {
            ChainFixture::withoutTriggers(COUNT_TRIGGERS, fn () => DB::table('stock_counts')->where('id', $t->links[1]->id)->update(['payload_hash' => str_repeat('b', 64)]));
        }, 2, 'hash_mismatch', 2],
        'a link deleted' => [function ($t) {
            ChainFixture::withoutTriggers(array_merge(COUNT_TRIGGERS, LINE_TRIGGERS), function () use ($t) {
                DB::table('stock_count_lines')->where('count_id', $t->links[1]->id)->delete();
                DB::table('stock_counts')->where('id', $t->links[1]->id)->delete();
            });
        }, 2, 'gap', 1],
        'two links swapped' => [function ($t) {
            ChainFixture::withoutTriggers(COUNT_TRIGGERS, function () use ($t) {
                DB::table('stock_counts')->where('id', $t->links[1]->id)->update(['chain_seq' => 99]);
                DB::table('stock_counts')->where('id', $t->links[2]->id)->update(['chain_seq' => 2]);
                DB::table('stock_counts')->where('id', $t->links[1]->id)->update(['chain_seq' => 3]);
            });
        }, 2, 'previous_mismatch', 3],
    ];
}

it('finds a tampered chain and names the first link that fails and how', function (Closure $tamper, int $chainSeq, string $kind, int $failures) {
    $this->links = $this->chain->chain($this->place, 4);
    expect(($this->verify)()['ok'])->toBeTrue();

    $tamper($this);

    $result = ($this->verify)();
    expect($result['ok'])->toBeFalse()
        ->and($result['failures'][0]['chain_seq'])->toBe($chainSeq)
        ->and($result['failures'][0]['kind'])->toBe($kind)
        ->and($result['failures'])->toHaveCount($failures);
    $named = $kind === 'gap' ? $this->links[2]->id : ($chainSeq === 2 && $kind === 'previous_mismatch' && $failures === 3 ? $this->links[2]->id : $this->links[$chainSeq - 1]->id);
    expect($result['failures'][0]['count_id'])->toBe($named);
})->with(array_map(fn ($case) => $case, tamperCases()));

it('puts the triggers back after a tamper, exactly as they were', function () {
    $this->chain->chain($this->place, 1);
    ChainFixture::withoutTriggers(COUNT_TRIGGERS, fn () => null);

    expect(fn () => DB::table('stock_counts')->where('chain_seq', 1)->update(['decision_note' => 'x']))->toThrow(QueryException::class);
});

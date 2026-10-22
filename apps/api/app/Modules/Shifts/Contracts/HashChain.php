<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Contracts;

use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Hash\ChainVerification;
use App\Modules\Shifts\Hash\PayloadBuilder;
use App\Modules\Shifts\Models\StockCount;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * manifest shifts.provides "HashChain::lock(count) / verify(location) / export(location)" (ADR 0012; phase-3 plan sections 3, 5 and
 * 10.3). The chain of a place is the locked rows of stock_counts at that place in chain_seq order: no table of its own, so the locked
 * row is the link and the trigger of migration 12 protects both.
 *
 * lock() turns an `approved` count into link n + 1: payload_hash = sha256(previous_hash . canonical_payload), where previous_hash is the
 * hash of link n (sixty-four zeros for the first link of a place) and canonical_payload is the version 1 payload (Hash\PayloadBuilder,
 * Hash\CanonicalJson) with chain_seq, locked_at (microseconds) and locked_by_user_id in it. It runs inside the caller's transaction (the
 * decision that approved the count), with the count row already locked.
 *
 * The sequence is claimed by the unique key (branch_id, location_id, chain_seq), never by a lock on a head row (there is none for the
 * first link, and a FOR UPDATE on a missing row is the first-row deadlock of traps.md). The head is read without a lock; two locks of one
 * place that read the same head both write n + 1: the second waits for the first's commit, gets a duplicate-key error (inside a
 * savepoint, so the transaction lives on, PostgreSQL included), reads the row that now exists with a locking read, whose hash is its
 * previous link, and tries n + 2. A stale read costs one more try, never a wrong link. After MAX_ATTEMPTS lost claims it is
 * 409 shifts.lock.chain_busy and the caller's transaction rolls back whole.
 *
 * verify() and export() read the live rows. A final class resolved per use (it carries nothing).
 */
final class HashChain
{
    public const string GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public const int MAX_ATTEMPTS = 8;

    public function __construct(private readonly PayloadBuilder $payloads) {}

    /**
     * @throws ShiftError shifts.lock.chain_busy (409)
     */
    public function lock(StockCount $count, int $lockedByUserId): StockCount
    {
        if ($count->status === CountStatus::Locked) {
            return $count;
        }
        $count->status->transitionTo(CountStatus::Locked); // only an approved count is locked
        $lockedAt = CarbonImmutable::now('UTC');

        $head = DB::table('stock_counts')
            ->where('branch_id', $count->branch_id)->where('location_id', $count->location_id)->whereNotNull('chain_seq')
            ->orderByDesc('chain_seq')->first(['chain_seq', 'payload_hash']);
        $last = $head === null ? 0 : (int) $head->chain_seq;
        $previous = $head === null ? self::GENESIS : (string) $head->payload_hash;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $seq = $last + 1;
            $payload = $this->payloads->canonical($count, $seq, $lockedAt, $lockedByUserId);

            $hash = hash('sha256', $previous.$payload);

            try {
                // one UPDATE by the query builder: nothing of the model's dirty state to reason about after a lost claim, and the
                // savepoint of DB::transaction keeps the surrounding transaction alive on both engines
                DB::transaction(function () use ($count, $seq, $previous, $hash, $payload, $lockedAt, $lockedByUserId): void {
                    DB::table('stock_counts')->where('id', $count->id)->update([
                        'status' => CountStatus::Locked->value,
                        'chain_seq' => $seq,
                        'previous_hash' => $previous,
                        'payload_hash' => $hash,
                        'payload_version' => PayloadBuilder::VERSION,
                        'canonical_payload' => $payload,
                        'locked_at' => $lockedAt->format('Y-m-d H:i:s.u'),
                        'locked_by_user_id' => $lockedByUserId,
                        'version' => $count->version + 1,
                        'updated_at' => $lockedAt->format('Y-m-d H:i:s'),
                    ]);
                });
                $count->refresh();

                return $count;
            } catch (UniqueConstraintViolationException) {
                // number $seq is taken, and its row exists now: its hash is the previous hash of whatever comes next
                $last = $seq;
                $taken = DB::table('stock_counts')
                    ->where('branch_id', $count->branch_id)->where('location_id', $count->location_id)->where('chain_seq', $seq)
                    ->sharedLock()->value('payload_hash');
                if ($taken === null) {
                    throw new LogicException("Chain number {$seq} was refused as taken, but no link has it.");
                }
                $previous = (string) $taken;
            }
        }

        throw ShiftError::chainBusy();
    }

    /**
     * Check the whole chain of the place (of the current branch): the numbering is gapless from 1, every previous_hash is the hash of
     * the link before it, every hash is sha256(previous_hash . canonical_payload) of the stored bytes, and the stored bytes are the
     * ones the live rows produce today. Reads only.
     */
    public function verify(int $locationId): ChainVerification
    {
        $expected = 1;
        $previous = self::GENESIS;
        $checked = 0;
        $head = null;
        $failures = [];

        $rows = StockCount::query()->where('location_id', $locationId)->whereNotNull('chain_seq')->orderBy('chain_seq')->cursor();
        foreach ($rows as $row) {
            $checked++;
            $seq = (int) $row->chain_seq;
            $kind = null;
            $named = $seq;
            if ($seq !== $expected) {
                $kind = 'gap';
                $named = $expected;
            } elseif ($row->previous_hash !== $previous) {
                $kind = 'previous_mismatch';
            } elseif (hash('sha256', (string) $row->previous_hash.(string) $row->canonical_payload) !== $row->payload_hash) {
                $kind = 'hash_mismatch';
            } elseif ($this->rebuilt($row) !== $row->canonical_payload) {
                $kind = 'payload_mismatch';
            }
            if ($kind !== null) {
                $failures[] = ['chain_seq' => $named, 'count_id' => $row->id, 'kind' => $kind];
            }
            $expected = $seq + 1;
            $previous = (string) $row->payload_hash;
            $head = $previous;
        }

        return new ChainVerification($checked, $head, $failures);
    }

    /**
     * Every link of the place with the bytes that were hashed, for a second implementation to recompute (scripts/verify_chain.py).
     *
     * @return array{format: string, version: int, branch_id: int, location_id: int, genesis_hash: string, head_hash: string|null, links: list<array{chain_seq: int, count_id: int, previous_hash: string, payload_hash: string, payload_version: int, canonical_payload: string}>}
     */
    public function export(int $locationId): array
    {
        $links = [];
        $branchId = 0;
        $head = null;
        foreach (StockCount::query()->where('location_id', $locationId)->whereNotNull('chain_seq')->orderBy('chain_seq')->cursor() as $row) {
            $branchId = $row->branch_id;
            $head = (string) $row->payload_hash;
            $links[] = [
                'chain_seq' => (int) $row->chain_seq,
                'count_id' => $row->id,
                'previous_hash' => (string) $row->previous_hash,
                'payload_hash' => $head,
                'payload_version' => (int) $row->payload_version,
                'canonical_payload' => (string) $row->canonical_payload,
            ];
        }

        return [
            'format' => 'bakery-chain-export',
            'version' => 1,
            'branch_id' => $branchId,
            'location_id' => $locationId,
            'genesis_hash' => self::GENESIS,
            'head_hash' => $head,
            'links' => $links,
        ];
    }

    /**
     * The ids of the places of the current branch that have at least one link.
     *
     * @return list<int>
     */
    public function locations(): array
    {
        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            StockCount::query()->whereNotNull('chain_seq')->distinct()->orderBy('location_id')->pluck('location_id')->all(),
        ));
    }

    private function rebuilt(StockCount $row): ?string
    {
        try {
            return $this->payloads->rebuild($row);
        } catch (LogicException) {
            return null;
        }
    }
}

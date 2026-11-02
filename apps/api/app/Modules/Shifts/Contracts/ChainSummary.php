<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Contracts;

/**
 * What HashChain::verifyAll found on one connection (contract ops->shifts, step 5.5b): how many chains (places with at least one link)
 * and links it checked over every branch, and every failure as {branch_id, location_id, chain_seq, count_id, kind} (the kinds of
 * Hash\ChainVerification) ordered by branch, place and link. `first_broken` is the first failure, null when every chain is whole.
 */
final readonly class ChainSummary
{
    /**
     * @param  list<array{branch_id: int, location_id: int, chain_seq: int, count_id: int, kind: string}>  $failures
     */
    public function __construct(
        public int $places,
        public int $links,
        public array $failures,
    ) {}

    public function ok(): bool
    {
        return $this->failures === [];
    }

    /**
     * @return array{branch_id: int, location_id: int, chain_seq: int, count_id: int, kind: string}|null
     */
    public function firstBroken(): ?array
    {
        return $this->failures[0] ?? null;
    }

    /**
     * @return array{ok: bool, places: int, links: int, first_broken: array{branch_id: int, location_id: int, chain_seq: int, count_id: int, kind: string}|null, failures: list<array{branch_id: int, location_id: int, chain_seq: int, count_id: int, kind: string}>}
     */
    public function toArray(): array
    {
        return ['ok' => $this->ok(), 'places' => $this->places, 'links' => $this->links, 'first_broken' => $this->firstBroken(), 'failures' => $this->failures];
    }
}

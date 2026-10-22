<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Hash;

/**
 * What HashChain::verify found for one place: whether every link held, how many were looked at, the hash of the last link as stored,
 * and each failure as {chain_seq, count_id, kind}. A link has at most one failure, the first that applies: `gap` (the sequence skips a
 * number: chain_seq is the number that is missing, count_id the link found in its place), `previous_mismatch` (its previous_hash is
 * not the hash of the link before it), `hash_mismatch` (its hash is not sha256(previous_hash . canonical_payload)), `payload_mismatch`
 * (the stored bytes are not what the live rows produce today).
 */
final readonly class ChainVerification
{
    /**
     * @param  list<array{chain_seq: int, count_id: int, kind: string}>  $failures
     */
    public function __construct(
        public int $checked,
        public ?string $headHash,
        public array $failures,
    ) {}

    public function ok(): bool
    {
        return $this->failures === [];
    }

    /**
     * @return array{ok: bool, checked: int, head_hash: string|null, failures: list<array{chain_seq: int, count_id: int, kind: string}>}
     */
    public function toArray(): array
    {
        return ['ok' => $this->ok(), 'checked' => $this->checked, 'head_hash' => $this->headHash, 'failures' => $this->failures];
    }
}

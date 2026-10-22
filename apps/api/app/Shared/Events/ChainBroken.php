<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The hash chain of a place failed its verification (phase-3 plan section 5, step 3.4): `shifts:verify-chain` dispatches one per failure
 * it finds, every night. `firstBadChainSeq` is the link that fails (the missing number for a `gap`), `kind` one of gap,
 * previous_mismatch, hash_mismatch, payload_mismatch (Shifts' ChainVerification), `countId` the count found at that link and
 * `causedByUserId` the person who locked it (the link's locked_by_user_id; null if none is recorded). Notifications subscribes without
 * importing Shifts and tells the admins; the inbox key makes one line per distinct break however often the check finds it.
 */
final class ChainBroken extends DomainEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        int $branchId,
        public readonly int $locationId,
        public readonly string $locationNameEn,
        public readonly string $locationNameAm,
        public readonly int $firstBadChainSeq,
        public readonly string $kind,
        public readonly int $countId,
        public readonly ?int $causedByUserId,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'location_id' => $this->locationId,
            'location_name_en' => $this->locationNameEn,
            'location_name_am' => $this->locationNameAm,
            'first_bad_chain_seq' => $this->firstBadChainSeq,
            'kind' => $this->kind,
            'count_id' => $this->countId,
            'caused_by_user_id' => $this->causedByUserId,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A receipt will not be printed (step 5.3a, phase-5 plan section 5; ADR 0019): its third claim failed (the bridge reported paper_out,
 * offline, cover_open or unknown) or went unacknowledged (print:sweep, failure_key unacknowledged). Dispatched by Payments after its
 * transaction commits, once per job. The listener that tells the cashier who asked and the managers on shift (Notifications
 * NotifyPrintFailed, with the print_failed notification type) comes with step 5.5c; until then the till sees the job failed on its poll.
 * Ids and a key only: never a token, a path or a host.
 */
final class PrintJobFailed extends DomainEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        int $branchId,
        public readonly int $jobId,
        public readonly int $orderId,
        public readonly ?int $orderNumber,
        public readonly string $failureKey,
        public readonly int $requestedByUserId,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array{job_id: int, order_id: int, order_number: int|null, failure_key: string, requested_by_user_id: int}
     */
    public function payload(): array
    {
        return [
            'job_id' => $this->jobId,
            'order_id' => $this->orderId,
            'order_number' => $this->orderNumber,
            'failure_key' => $this->failureKey,
            'requested_by_user_id' => $this->requestedByUserId,
        ];
    }
}

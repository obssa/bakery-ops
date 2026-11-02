<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support\Printing;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Contracts\BridgeDevice;
use App\Modules\Identity\Contracts\Bridges;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Payments\Enums\PrintJobStatus;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Models\PrintJob;
use App\Modules\Payments\Support\PaymentAccess;
use App\Shared\Actor\ActorContext;
use App\Shared\Events\PrintJobFailed;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The life of a print job (step 5.3a, phase-5 plan 4.3; ADR 0019), one transaction and one audit row per change:
 *   request  the till asks (POST /orders/{order}/print): only an order paid in full (409 payments.print.not_paid), only while a bridge of
 *            the branch was seen in print.bridge_seen_seconds (409 payments.print.no_bridge); a client_uuid already used answers its job;
 *            print_job.create, as the person (and the one acting by short code, who must hold print.create too);
 *   next     the oldest queued job of the bridge's branch that is not too old to print: one indexed read, no write;
 *   claim    the job's row is locked; a queued job (or one whose claim nobody acknowledged for print.claim_ack_seconds) becomes the
 *            bridge's, attempts + 1, print_job.claim; the same bridge asking again gets the same answer and nothing changes; another bridge
 *            gets 409 payments.print.already_claimed; a job older than print.expire_minutes is expired then and answered 410;
 *   printed  the bridge that holds it says it printed: print_job.printed; again: 204, nothing changes; anyone else 409 not_claimed_by_you;
 *   failed   the bridge that holds it says why not: back to queued, or failed after the print.max_claims-th claim (PrintJobFailed after the
 *            commit); again: 204, nothing changes;
 *   sweep    print:sweep, every minute: an unacknowledged claim goes back (or fails after the last claim, failure_key unacknowledged) and a
 *            job older than print.expire_minutes that nobody is printing is expired, in every branch.
 * A bridge has no user: its changes, and the sweep's, are written as the person who asked for the print (ActorContext::runAs with the
 * job's actor stamp; ADR 0011's queued actor) on the bridge's device. Never SELECT ... FOR UPDATE on a row that may be missing: a job is
 * read (404) before its row is locked.
 */
final class PrintJobs
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ActorContext $actor,
        private readonly CurrentBranch $branch,
        private readonly Orders $orders,
        private readonly Bridges $bridges,
        private readonly PaymentAccess $access,
    ) {}

    /**
     * @return array{0: PrintJob, 1: bool} the job, and whether this call queued it
     *
     * @throws ModelNotFoundException an order of another branch, or none
     * @throws PaymentError payments.print.not_paid, payments.print.no_bridge, auth.forbidden
     */
    public function request(int $orderId, int $copies, string $clientUuid): array
    {
        $this->access->person('print.create');
        $existing = PrintJob::query()->where('client_uuid', $clientUuid)->first();
        if ($existing instanceof PrintJob) {
            return [$existing, false];
        }

        $order = $this->orders->forReceipt($orderId);
        if (in_array($order->status, ['cancelled', 'voided'], true) || ! $order->isSettled()) {
            throw PaymentError::printNotPaid($order->status);
        }
        if (! $this->bridges->seenWithin((int) config('print.bridge_seen_seconds', 120))) {
            throw PaymentError::printNoBridge();
        }

        try {
            $job = $this->audit->run(fn (): PrintJob => PrintJob::query()->create([
                'branch_id' => $this->branch->id(),
                'client_uuid' => $clientUuid,
                'order_id' => $order->orderId,
                'kind' => PrintJob::KIND_RECEIPT,
                'copies' => $copies,
                'status' => PrintJobStatus::Queued,
                'attempts' => 0,
            ] + $this->actor->stamp()), null, 'print_job.create', ['order_id' => $order->orderId, 'copies' => $copies]);
        } catch (UniqueConstraintViolationException) {
            // the same client_uuid queued by a request that ran at the same moment
            return [PrintJob::query()->where('client_uuid', $clientUuid)->firstOrFail(), false];
        }

        return [$job, true];
    }

    /** The oldest queued job of the current branch that is young enough to print, or null. */
    public function next(): ?int
    {
        $id = PrintJob::query()
            ->where('status', PrintJobStatus::Queued->value)
            ->where('created_at', '>', self::expiryCutoff())
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @throws ModelNotFoundException a job of another branch, or none
     * @throws PaymentError payments.print.already_claimed, payments.print.expired (410)
     */
    public function claim(BridgeDevice $bridge, int $jobId, string $clientUuid): PrintJob
    {
        PrintJob::query()->findOrFail($jobId);

        [$outcome, $job] = DB::transaction(function () use ($bridge, $jobId, $clientUuid): array {
            $job = PrintJob::query()->whereKey($jobId)->lockForUpdate()->firstOrFail();
            if ($job->status === PrintJobStatus::Claimed && $job->claimed_by_device_id === $bridge->id && ! $job->claimIsStale()) {
                return ['held_by_you', $job];
            }
            $takeover = $job->status === PrintJobStatus::Claimed && $job->claimIsStale() && $job->attempts < self::maxClaims();
            if ($job->status !== PrintJobStatus::Queued && ! $takeover) {
                return ['held', $job];
            }
            if ($job->created_at->lessThanOrEqualTo(self::expiryCutoff())) {
                $this->change($job, $bridge->id, 'print_job.expire', ['status' => PrintJobStatus::Expired], ['reason' => 'too_old']);

                return ['expired', $job];
            }
            $this->change($job, $bridge->id, 'print_job.claim', [
                'status' => PrintJobStatus::Claimed,
                'claimed_by_device_id' => $bridge->id,
                'claimed_at' => CarbonImmutable::now(),
                'attempts' => $job->attempts + 1,
            ], ['client_uuid' => $clientUuid, 'attempt' => $job->attempts + 1, 'taken_over' => $takeover]);

            return ['claimed', $job];
        });

        return match ($outcome) {
            'claimed', 'held_by_you' => $job,
            'expired' => throw PaymentError::printExpired(),
            default => throw PaymentError::printAlreadyClaimed($job->status->value),
        };
    }

    /**
     * @throws ModelNotFoundException a job of another branch, or none
     * @throws PaymentError payments.print.not_claimed_by_you
     */
    public function printed(BridgeDevice $bridge, int $jobId, string $clientUuid): void
    {
        PrintJob::query()->findOrFail($jobId);

        DB::transaction(function () use ($bridge, $jobId, $clientUuid): void {
            $job = PrintJob::query()->whereKey($jobId)->lockForUpdate()->firstOrFail();
            if ($job->claimed_by_device_id !== $bridge->id || ! in_array($job->status, [PrintJobStatus::Claimed, PrintJobStatus::Printed], true)) {
                throw PaymentError::printNotClaimedByYou($job->status->value);
            }
            if ($job->status === PrintJobStatus::Printed) {
                return;
            }
            $this->change($job, $bridge->id, 'print_job.printed', ['status' => PrintJobStatus::Printed, 'printed_at' => CarbonImmutable::now()], ['client_uuid' => $clientUuid]);
        });
    }

    /**
     * @throws ModelNotFoundException a job of another branch, or none
     * @throws PaymentError payments.print.not_claimed_by_you
     */
    public function failed(BridgeDevice $bridge, int $jobId, string $failureKey, string $clientUuid): void
    {
        PrintJob::query()->findOrFail($jobId);

        DB::transaction(function () use ($bridge, $jobId, $failureKey, $clientUuid): void {
            $job = PrintJob::query()->whereKey($jobId)->lockForUpdate()->firstOrFail();
            $mine = $job->claimed_by_device_id === $bridge->id;
            if ($mine && $job->status === PrintJobStatus::Claimed) {
                $this->fail($job, $bridge->id, $failureKey, ['client_uuid' => $clientUuid]);

                return;
            }
            $reported = $mine && in_array($job->status, [PrintJobStatus::Queued, PrintJobStatus::Failed], true)
                && $job->failed_at !== null && $job->claimed_at !== null && $job->failed_at->greaterThanOrEqualTo($job->claimed_at);
            if (! $reported) {
                throw PaymentError::printNotClaimedByYou($job->status->value);
            }
        });
    }

    /**
     * print:sweep: every branch's unacknowledged claims and jobs too old to print.
     *
     * @return array{requeued: int, failed: int, expired: int}
     */
    public function sweep(): array
    {
        $counts = ['requeued' => 0, 'failed' => 0, 'expired' => 0];
        $old = self::expiryCutoff();
        $stale = CarbonImmutable::now()->subSeconds((int) config('print.claim_ack_seconds', 60));
        $candidates = PrintJob::query()->withoutGlobalScope(BranchScope::class)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', PrintJobStatus::Queued->value)->where('created_at', '<=', $old))
                ->orWhere(fn ($q) => $q->where('status', PrintJobStatus::Claimed->value)->where('claimed_at', '<=', $stale)))
            ->orderBy('id')
            ->get(['id', 'branch_id']);

        foreach ($candidates as $candidate) {
            $result = $this->branch->runAs($candidate->branch_id, fn (): ?string => DB::transaction(function () use ($candidate, $old): ?string {
                $job = PrintJob::query()->whereKey($candidate->id)->lockForUpdate()->first();
                if (! $job instanceof PrintJob) {
                    return null;
                }
                $tooOld = $job->created_at->lessThanOrEqualTo($old);
                if ($tooOld && ($job->status === PrintJobStatus::Queued || $job->claimIsStale())) {
                    $this->change($job, null, 'print_job.expire', ['status' => PrintJobStatus::Expired], ['reason' => 'too_old']);

                    return 'expired';
                }
                if (! $job->claimIsStale()) {
                    return null;
                }
                if ($job->attempts >= self::maxClaims()) {
                    $this->fail($job, null, PrintJob::UNACKNOWLEDGED, []);

                    return 'failed';
                }
                $this->change($job, null, 'print_job.requeue', ['status' => PrintJobStatus::Queued], ['reason' => 'unacknowledged', 'attempt' => $job->attempts]);

                return 'requeued';
            }));
            if ($result !== null) {
                $counts[$result]++;
            }
        }

        return $counts;
    }

    /**
     * A failure of the job's current claim: back to the queue while claims remain, else failed for good and PrintJobFailed after the
     * commit. Audited print_job.failed.
     *
     * @param  array<string, mixed>  $context
     */
    private function fail(PrintJob $job, ?int $deviceId, string $failureKey, array $context): void
    {
        $final = $job->attempts >= self::maxClaims();
        $this->change($job, $deviceId, 'print_job.failed', [
            'status' => $final ? PrintJobStatus::Failed : PrintJobStatus::Queued,
            'failure_key' => $failureKey,
            'failed_at' => CarbonImmutable::now(),
        ], $context + ['failure_key' => $failureKey, 'attempt' => $job->attempts, 'final' => $final]);
        if ($final) {
            event(new PrintJobFailed(
                $job->branch_id,
                $job->id,
                $job->order_id,
                ($this->orders->summaries([$job->order_id])[$job->order_id] ?? null)?->orderNo,
                $failureKey,
                $job->created_by_user_id,
            ));
        }
    }

    /**
     * One audited change of a job, written as the person who asked for it on $deviceId (a bridge's, or none for the sweep).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $context
     */
    private function change(PrintJob $job, ?int $deviceId, string $action, array $attributes, array $context): void
    {
        if (isset($attributes['status']) && $attributes['status'] instanceof PrintJobStatus && $attributes['status'] !== $job->status) {
            $job->status->transitionTo($attributes['status']);
        }
        $this->actor->runAs($job->requesterStamp($deviceId), fn () => $this->audit->run(function (PrintJob $subject) use ($attributes): PrintJob {
            $subject->forceFill($attributes)->save();

            return $subject;
        }, $job, $action, $context + ['order_id' => $job->order_id]));
    }

    private static function expiryCutoff(): CarbonImmutable
    {
        return CarbonImmutable::now()->subMinutes((int) config('print.expire_minutes', 15));
    }

    private static function maxClaims(): int
    {
        return max(1, (int) config('print.max_claims', 3));
    }
}

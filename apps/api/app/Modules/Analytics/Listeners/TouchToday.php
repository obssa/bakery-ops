<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Listeners;

use App\Modules\Analytics\Enums\RebuildTrigger;
use App\Modules\Analytics\Support\RebuildQueue;
use App\Shared\Events\DomainEvent;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * Today's figures moved (phase-4 plan section 5, step 4.4b; ADR 0014): a payment was taken, verified, disputed or voided, an order was paid or
 * voided. Queued after the commit (ShouldQueueAfterCommit: PaymentRecorded and PaymentVoided are dispatched inside the payment's transaction,
 * and a queued listener does not read ShouldHandleEventsAfterCommit), so a payment that rolled back touches nothing and the till never waits
 * for it. Asks for
 * RebuildDay(branch, the local day the event happened, trigger touch) analytics.touch_delay_seconds (60) out; RebuildDay is unique per branch
 * and day (analytics.touch_unique_seconds, 120 s, the cache's atomic lock) and lets the lock go when it starts, so twenty payments in a minute
 * give one rebuild that reads all of them and a payment taken while it runs asks for the next. With the cron-drained queue of the live host
 * today is at most about three minutes behind the till. The day is the event's own (a payment at 23:59:30 handled at 00:00:40 rebuilds the
 * day it was taken on), never a day after today; a branch that no longer exists is skipped. A day that is not today is rebuilt by its
 * shift's lock, the nightly run or by hand. Refunds (OrderRefunded, RefundPaid) join with step 4.1b and 4.3d.
 */
final class TouchToday implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    public function __construct(private readonly RebuildQueue $queue) {}

    public function handle(DomainEvent $event): void
    {
        $branch = Branch::query()->find($event->branchId);
        if (! $branch instanceof Branch) {
            return;
        }
        $zone = $branch->timezone;
        $today = CarbonImmutable::now($zone)->toDateString();
        $day = min($event->occurredAt->setTimezone($zone)->toDateString(), $today);

        $this->queue->ask($branch->id, $day, RebuildTrigger::Touch, max(0, (int) config('analytics.touch_delay_seconds', 60)));
    }
}

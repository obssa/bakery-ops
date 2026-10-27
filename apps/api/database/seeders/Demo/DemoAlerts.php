<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Analytics\Jobs\RebuildDay;
use App\Modules\Inventory\Jobs\EvaluateBalance;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Testing\Fakes\BusFake;

/**
 * The two jobs the Phase 4 history holds back while it is played (step 4.6a), because asking for them per movement and per payment costs
 * more than the rest of the seed: `RebuildDay` (the touch and shift-lock listeners ask for one per payment and per lock; the seed rebuilds
 * every day once at the end instead) and `EvaluateBalance` (the stock-alert evaluation Inventory asks for on every movement). Both are
 * held on the bus (a BusFake that fakes these two jobs only; every other job and every queued listener still runs, synchronously) and:
 *
 *   flush()   evaluates every balance the held EvaluateBalance jobs name, once each, through the real bus: the stock-outs, the low-stock
 *             alerts, their recoveries and the inbox lines they cause happen as on the screens, at the time of the flush. DemoDay flushes
 *             after each day's closings, and the till and the restock flush around a planned stock-out, so its automatic production
 *             request is opened that morning;
 *   release() puts the real bus back.
 * Running a held job also lets its unique lock go (the lock is taken when the job is asked for, before the bus sees it), so a balance
 * flushed today is asked for, and held, again tomorrow.
 */
final class DemoAlerts
{
    private BusFake $fake;

    public function __construct(private readonly QueueingDispatcher $bus)
    {
        $this->fake = $this->hold();
    }

    public function flush(): void
    {
        $held = $this->fake->dispatched(EvaluateBalance::class);
        // the real bus while they run: the queue worker hands a job to the bus to run it, and a fake would hold it again
        Bus::swap($this->bus);
        try {
            $seen = [];
            foreach ($held as $job) {
                if (! $job instanceof EvaluateBalance || isset($seen[$job->uniqueId()])) {
                    continue;
                }
                $seen[$job->uniqueId()] = true;
                // a fresh job: the held one carries what the dispatch attached to it and cannot be queued again; running it lets the
                // balance's unique lock go whoever took it
                $this->bus->dispatchSync(new EvaluateBalance($job->branchId, $job->itemId, $job->locationId));
            }
        } finally {
            $this->fake = $this->hold();
        }
    }

    public function release(): void
    {
        Bus::swap($this->bus);
    }

    private function hold(): BusFake
    {
        $fake = new BusFake($this->bus, [RebuildDay::class, EvaluateBalance::class]);
        Bus::swap($fake);

        return $fake;
    }
}

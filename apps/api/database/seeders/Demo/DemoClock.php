<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\Phase0Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The clock of a fresh demo branch (step 3.8; phase-3 plan section 8; step 4.6a, phase-4 plan section 8). A locked history is only
 * consistent with the ledger if its movements happened before its counts, and the Phase 0 to 2 seeders write the opening stock "now".
 * So when a history is asked for and the demo branch is fresh, around() sets the clock (both Carbon and CarbonImmutable) to 05:30 local
 * on the anchor day, daysBackFor($phase) days ago, runs the seeders, and puts the real clock back in a finally. The history seeder then
 * moves the clock forward day by day and returns to the real time for today's part:
 *
 *   SEED_PHASE 3: the anchor is three days back and Phase3Seeder plays the two days after it (exactly as step 3.8 built it);
 *   SEED_PHASE 4 and above: the anchor is DayPlan::historyDays() + 2 days back (92 with the default 90) and Phase4Seeder plays every day
 *   after it up to yesterday with the Phase 4 day plan; Phase3Seeder then writes only its templates and reasons. The queue is `sync` for
 *   the whole run (put back afterwards), so nothing is left for a worker to evaluate in real time.
 *
 * A branch that already holds data (the live demo) is never anchored: the ledger is append-only and nothing may be written under rows
 * it has already shown, so the clock is not touched and no history is written. For Phase 3 "data" is a movement or a shift (step 3.8's
 * rule, unchanged); from Phase 4 an order counts too.
 */
final class DemoClock
{
    /** The timezone Phase0Seeder gives the demo branch. */
    public const string TIMEZONE = 'Africa/Addis_Ababa';

    /** Phase 3's anchor: three days back, two days of history. */
    public const int PHASE_THREE_DAYS_BACK = 3;

    private static ?CarbonImmutable $anchor = null;

    private static ?int $phase = null;

    private static ?CarbonImmutable $real = null;

    private static bool $realWasFrozen = false;

    private static bool $moving = false;

    /** Is the clock moved by around() right now? */
    public static function anchored(): bool
    {
        return self::$anchor instanceof CarbonImmutable;
    }

    /** The phase around() anchored the clock for (3: Phase 3's two days; 4 or more: the Phase 4 history), null when not anchored. */
    public static function anchoredPhase(): ?int
    {
        return self::anchored() ? self::$phase : null;
    }

    /** Does the anchored clock belong to the Phase 4 history (Phase4Seeder plays the days, Phase3Seeder only sets up)? */
    public static function playsPhaseFourHistory(): bool
    {
        return (self::anchoredPhase() ?? 0) >= 4;
    }

    /** The anchor instant (05:30 local on the anchor day) while anchored. */
    public static function anchor(): CarbonImmutable
    {
        return self::$anchor ?? throw new \LogicException('The demo clock is not anchored.');
    }

    /**
     * How many days back the anchor is for a run up to $phase: 3 below Phase 4 (so e2e-p0 to e2e-p3 and every earlier seeder test see
     * exactly what they saw), else the Phase 4 history's days plus two (the anchor day and the opening day before the window).
     */
    public static function daysBackFor(int $phase): int
    {
        return $phase >= 4 ? DayPlan::historyDays() + 2 : self::PHASE_THREE_DAYS_BACK;
    }

    /** The real time of the run (the test clock when a test froze it). */
    public static function real(): CarbonImmutable
    {
        return self::$real ?? CarbonImmutable::now();
    }

    /** Move the clock to an instant of the history. */
    public static function set(CarbonImmutable $at): void
    {
        self::$moving = true;
        Carbon::setTestNow($at);
    }

    /** A few seconds on, while the history is being played (a decision never shares the second of the snapshot it decides on). */
    public static function tick(int $seconds): void
    {
        if (self::$moving) {
            Carbon::setTestNow(CarbonImmutable::now()->addSeconds($seconds));
        }
    }

    /**
     * On to the next second of the clock, so a count's snapshot never shares the second of a movement written after it (a movement's
     * created_at has whole seconds, a snapshot microseconds): a tick while the history is played; in real time a wait (or a frozen test
     * clock moved on).
     */
    public static function nextSecond(): void
    {
        if (self::$moving) {
            self::tick(1);

            return;
        }
        if (Carbon::hasTestNow()) {
            Carbon::setTestNow(CarbonImmutable::now()->addSecond());

            return;
        }
        usleep(1_000_000 - (int) CarbonImmutable::now()->format('u') + 1_000);
    }

    /** Back to the real time, for today's part of the seed. */
    public static function toReal(): void
    {
        self::$moving = false;
        Carbon::setTestNow(self::$realWasFrozen ? self::$real : null);
    }

    /**
     * The local date a day's sample orders are keyed by: today, in real time, even while the clock is anchored in the past, so a second
     * run on the same day finds them and writes nothing.
     */
    public static function dayKey(string $timezone): string
    {
        return self::real()->setTimezone($timezone)->toDateString();
    }

    /**
     * Is the demo branch fresh enough to write a history under? From Phase 3, when the branch does not exist yet or has no movement
     * and no shift; from Phase 4 it must have no order either.
     */
    public static function wantsAnchor(int $phase): bool
    {
        if ($phase < 3) {
            return false;
        }
        $branch = Phase0Seeder::existingBranch();
        if (! $branch instanceof Branch) {
            return true;
        }

        return DB::table('stock_movements')->where('branch_id', $branch->id)->doesntExist()
            && DB::table('shift_instances')->where('branch_id', $branch->id)->doesntExist()
            && ($phase < 4 || DB::table('orders')->where('branch_id', $branch->id)->doesntExist());
    }

    /**
     * Run $seed with the clock anchored when the branch is fresh, else just run it.
     *
     * @param  Closure(): void  $seed
     */
    public static function around(int $phase, Closure $seed): void
    {
        if (! self::wantsAnchor($phase)) {
            $seed();

            return;
        }

        self::$real = CarbonImmutable::now();
        self::$realWasFrozen = Carbon::hasTestNow();
        $local = self::$real->setTimezone(self::TIMEZONE);
        self::$anchor = $local->subDays(self::daysBackFor($phase))->setTime(5, 30)->utc();
        self::$phase = $phase;
        self::set(self::$anchor);
        // the Phase 4 history: every seeder's queued work runs here and now, in the seeded clock (Phases 0 and 1 do not set the queue
        // themselves; on the queue a worker would evaluate their balances in real time, and the unique locks of those jobs would hold
        // back the history's own evaluations, its stock-outs among them, for ten minutes)
        $previousQueue = config('queue.default');
        if ($phase >= 4) {
            config(['queue.default' => 'sync']);
        }
        try {
            $seed();
        } finally {
            config(['queue.default' => $previousQueue]);
            self::toReal();
            self::$anchor = null;
            self::$phase = null;
            self::$real = null;
            self::$realWasFrozen = false;
        }
    }
}

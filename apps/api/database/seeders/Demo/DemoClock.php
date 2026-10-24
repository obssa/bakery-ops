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
 * The clock of a fresh demo branch (step 3.8; phase-3 plan section 8). A locked history is only consistent with the ledger if its
 * movements happened before its counts, and the Phase 0 to 2 seeders write the opening stock "now". So when Phase 3 is asked for and the
 * demo branch is fresh (it does not exist yet, or holds no stock movement and no shift), around() sets the clock to 05:30 local, three
 * days ago (both Carbon and CarbonImmutable), runs the seeders, and puts the real clock back in a finally. Phase3Seeder then moves the
 * clock forward day by day and returns to the real time for today's part.
 *
 * A branch that already holds movements (the live demo, loaded on Phases 0 to 2) is never anchored: the ledger is append-only and
 * nothing may be written under rows it has already shown, so the clock is not touched and Phase3Seeder writes no history.
 */
final class DemoClock
{
    /** The timezone Phase0Seeder gives the demo branch. */
    public const string TIMEZONE = 'Africa/Addis_Ababa';

    private static ?CarbonImmutable $anchor = null;

    private static ?CarbonImmutable $real = null;

    private static bool $realWasFrozen = false;

    private static bool $moving = false;

    /** Is the clock moved by around() right now? */
    public static function anchored(): bool
    {
        return self::$anchor instanceof CarbonImmutable;
    }

    /** The anchor instant (05:30 local, three days ago) while anchored. */
    public static function anchor(): CarbonImmutable
    {
        return self::$anchor ?? throw new \LogicException('The demo clock is not anchored.');
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
     * Is the demo branch fresh enough to write a history under? True when Phase 3 is requested and the branch has no movement and no shift.
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
            && DB::table('shift_instances')->where('branch_id', $branch->id)->doesntExist();
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
        self::$anchor = $local->subDays(3)->setTime(5, 30)->utc();
        self::set(self::$anchor);
        try {
            $seed();
        } finally {
            self::toReal();
            self::$anchor = null;
            self::$real = null;
            self::$realWasFrozen = false;
        }
    }
}

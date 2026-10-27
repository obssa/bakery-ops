<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Identity\Models\User;
use App\Shared\Models\Branch;
use App\Shared\Time\LocalDay;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\Phase0Seeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/**
 * The seeded history the gate:p4 tests read (phase-4 plan 10.3; section 6, 4.6b). The gate-p4 CI job seeds the demo bakery once on the
 * compose stack (SEED_PHASE=4, DEMO_HISTORY_DAYS days of history, `php artisan migrate:fresh --seed --seeder=DemoSeeder`) and then runs
 * the gate:p4 group in the app container with
 *   GATE_SEED_DATABASE  the database the seed wrote (the stack's DB_DATABASE, `bakery`);
 *   GATE_SEED_DAYS      the days of history it played (the job's DEMO_HISTORY_DAYS).
 * phpunit.xml pins the default connection to bakery_test with a <server> entry, which no environment variable overrides, so these tests
 * point the default connection at GATE_SEED_DATABASE themselves, and the other gate:p4 tests of the same process (the race tests,
 * DatabaseTruncation) keep bakery_test: nothing that runs beside them can wipe the seed. They never use RefreshDatabase or
 * DatabaseTruncation, and they write nothing but what a rebuild writes (RebuildIdempotentGateTest) and the export audit rows of
 * DashboardsReadSummariesOnlyGateTest.
 *
 * They fail, never skip: without the two variables, without the demo branch (Phase0Seeder::existingBranch()), or when fewer than
 * GATE_SEED_DAYS days before today are built (analytics_days) or one of them sold nothing, the test says what is missing and how the
 * job seeds it.
 */
final class SeededGate
{
    public const string HOW = 'seed with `SEED_PHASE=4 DEMO_HISTORY_DAYS=<n> php artisan migrate:fresh --force --seed --seeder=DemoSeeder` on the compose stack, '
        .'then run `pest --group=gate:p4` with GATE_SEED_DATABASE=<that database> and GATE_SEED_DAYS=<n> (the gate-p4 job of .github/workflows/ci.yml)';

    /** @param  list<string>  $window  the GATE_SEED_DAYS local days before today, oldest first */
    private function __construct(
        public readonly Branch $branch,
        public readonly string $database,
        public readonly int $days,
        public readonly array $window,
    ) {}

    public static function open(): self
    {
        $database = trim((string) getenv('GATE_SEED_DATABASE'));
        $days = trim((string) getenv('GATE_SEED_DAYS'));
        if ($database === '' || preg_match('/^[1-9][0-9]{0,3}$/', $days) !== 1) {
            Assert::fail('GATE_SEED_DATABASE and GATE_SEED_DAYS must name the seeded database and its days of history: '.self::HOW.'.');
        }
        $connection = (string) config('database.default');
        config(["database.connections.{$connection}.database" => $database]);
        DB::purge($connection);

        $branch = Phase0Seeder::existingBranch();
        if ($branch === null) {
            Assert::fail("{$database} holds no demo branch (Phase0Seeder::existingBranch()): ".self::HOW.'.');
        }
        actInBranch($branch);

        $count = (int) $days;
        $today = CarbonImmutable::now($branch->timezone)->startOfDay();
        $window = [];
        for ($n = $count; $n >= 1; $n--) {
            $window[] = $today->subDays($n)->toDateString();
        }
        $built = DB::table('analytics_days')->where('branch_id', $branch->id)->whereBetween('day', [$window[0], $window[$count - 1]])
            ->pluck('day')->map(fn (mixed $day): string => substr((string) $day, 0, 10))->all();
        $missing = array_values(array_diff($window, $built));
        if ($missing !== []) {
            Assert::fail(sprintf('%d of the %d days before today are not built in %s (first missing: %s): %s.', count($missing), $count, $database, $missing[0], self::HOW));
        }
        $sold = DB::table('daily_sales')->where('branch_id', $branch->id)->whereBetween('day', [$window[0], $window[$count - 1]])->where('orders_count', '>', 0)
            ->pluck('day')->map(fn (mixed $day): string => substr((string) $day, 0, 10))->all();
        $empty = array_values(array_diff($window, $sold));
        if ($empty !== []) {
            Assert::fail(sprintf('%d of the %d seeded days sold nothing (first: %s): the history is not the seed this gate reads (%s).', count($empty), $count, $empty[0], self::HOW));
        }

        return new self($branch, $database, $count, $window);
    }

    /** Every built day of the branch (the anchor, the opening day, the window and today), oldest first. @return list<string> */
    public function builtDays(): array
    {
        return DB::table('analytics_days')->where('branch_id', $this->branch->id)->orderBy('day')->pluck('day')
            ->map(fn (mixed $day): string => substr((string) $day, 0, 10))->values()->all();
    }

    /** The demo branch's first active person of the role (the demo bakery has one admin, manager, cashier, chef, ...). */
    public function person(string $role): User
    {
        $person = app(Recipients::class)->byRoles([$role])->first();
        if ($person === null) {
            Assert::fail("the demo branch has no active {$role}: ".self::HOW.'.');
        }

        return $person;
    }

    /** The product that took the most money in the window (the product page the dashboard tests read). */
    public function topProduct(): int
    {
        [$from, $to] = $this->range();
        $item = DB::table('daily_item_metrics')->where('branch_id', $this->branch->id)->where('kind', 'product')->whereBetween('day', [$from, $to])
            ->groupBy('item_id')->orderByRaw('SUM(revenue) DESC')->orderBy('item_id')->value('item_id');
        if ($item === null) {
            Assert::fail('no product sold in the seeded window: '.self::HOW.'.');
        }

        return (int) $item;
    }

    /** The first and the last day of the window. @return array{0: string, 1: string} */
    public function range(): array
    {
        return [$this->window[0], $this->window[count($this->window) - 1]];
    }

    /** The UTC bounds [start, end) of a local day of the branch. @return array{0: string, 1: string} */
    public function bounds(string $day): array
    {
        [$start, $end] = LocalDay::of($day, $this->branch->timezone)->utcBounds();

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    /** The exact sum of decimal strings (brick/math), at scale 2. */
    public static function sum(iterable $amounts): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($amounts as $amount) {
            $sum = $sum->plus(BigDecimal::of((string) $amount));
        }

        return $sum->toScale(2);
    }

    /** A decimal column as brick/math (a missing row counts as zero). */
    public static function of(mixed $value): BigDecimal
    {
        return BigDecimal::of($value === null ? '0' : (string) $value)->toScale(2);
    }
}

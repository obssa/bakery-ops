<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Shifts\Contracts\HashChain;
use App\Modules\Shifts\Enums\CountDecision;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\StockCountLine;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Counts and chain links for the step 3.4 tests, built from rows (factories) and locked through Contracts\HashChain, so the hash rule is
 * tested without the approval around it. approved() makes a closing count of a place in a fresh locked shift (one shift per count, a
 * later date each) in the status the actions leave it before the lock: `approved`, with its lines made while it was a draft. link() locks it.
 * withoutTriggers() is how a test plays the person with write access to the database: it drops the named triggers, runs the tamper
 * and puts them back exactly as SHOW CREATE TRIGGER printed them (DDL commits, so the test must use CommitsForConcurrency).
 */
final class ChainFixture
{
    private int $day = 0;

    public function __construct(private readonly ShiftsFixture $f) {}

    /**
     * @param  list<array{item: Item, counted: string, expected: string, reason?: string, note?: string}>  $lines
     * @param  array<string, mixed>  $over  count attributes
     */
    public function approved(Location $place, array $lines = [], array $over = []): StockCount
    {
        $this->day++;
        $shift = ShiftInstance::factory()->locked()->create([
            'branch_id' => $this->f->branch->id, 'station_id' => $place->station_id, 'created_by_user_id' => $this->f->chef->id,
            'date' => CarbonImmutable::parse('2026-10-01')->addDays($this->day)->toDateString(),
        ]);
        $now = CarbonImmutable::parse('2026-11-02 12:30:00', 'UTC');
        $count = StockCount::factory()->forShift($shift, $place->id)->closing()->status(CountStatus::Approved)->create($over + [
            'created_by_user_id' => $this->f->chef->id,
            'counted_by_user_id' => $this->f->chef->id,
            'decision' => CountDecision::Approved,
            'decided_by_user_id' => $this->f->admin->id,
            'decided_at' => $now,
            'decision_note' => null,
        ]);
        foreach ($lines as $line) {
            $factory = StockCountLine::factory()->forCount($count, $line['item']->id)->counted($line['counted'], $line['expected']);
            $attributes = [];
            if (isset($line['reason'])) {
                $attributes['variance_reason_id'] = $this->f->reasonId($line['reason']);
            }
            if (isset($line['note'])) {
                $attributes['variance_note'] = $line['note'];
            }
            $factory->create($attributes);
        }

        return $count->fresh() ?? $count;
    }

    /**
     * @param  list<array{item: Item, counted: string, expected: string, reason?: string, note?: string}>  $lines
     * @param  array<string, mixed>  $over
     */
    public function link(Location $place, array $lines = [], array $over = []): StockCount
    {
        $count = $this->approved($place, $lines, $over);

        return DB::transaction(fn (): StockCount => app(HashChain::class)->lock($count, $this->f->admin->id));
    }

    /**
     * A chain of $n links at the place, each with one line.
     *
     * @return list<StockCount>
     */
    public function chain(Location $place, int $n, ?Item $item = null): array
    {
        $item ??= $this->f->ingredient();
        $links = [];
        for ($i = 1; $i <= $n; $i++) {
            $links[] = $this->link($place, [['item' => $item, 'counted' => (string) (1000 - $i * 10), 'expected' => (string) (1000 - ($i - 1) * 10), 'reason' => 'spoilage', 'note' => "link {$i}"]]);
        }

        return $links;
    }

    /**
     * @template T
     *
     * @param  list<string>  $names  trigger names
     * @param  Closure(): T  $tamper
     * @return T
     */
    public static function withoutTriggers(array $names, Closure $tamper): mixed
    {
        $definitions = [];
        foreach ($names as $name) {
            $row = (array) DB::selectOne("SHOW CREATE TRIGGER `{$name}`");
            $definitions[$name] = (string) ($row['SQL Original Statement'] ?? '');
            expect($definitions[$name])->not->toBe('', "trigger {$name} exists");
        }
        foreach ($names as $name) {
            DB::unprepared("DROP TRIGGER `{$name}`");
        }

        try {
            return $tamper();
        } finally {
            foreach ($definitions as $sql) {
                DB::unprepared($sql);
            }
        }
    }
}

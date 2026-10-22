<?php

declare(strict_types=1);

use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Shifts\Contracts\Counts;
use App\Modules\Shifts\Contracts\Shifts;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'property');

/*
 * Step 3.3a (phase-3 plan section 7, CountsPropertyTest; the counts half of CountsLedgerPropertyTest): random sequences of stock
 * movements at two places of one station, interleaved with shifts that start counted or carried, close, and have their counts
 * entered and submitted (some refused: incomplete, a reason missing, blocked by an unresolved count). Whatever the sequence:
 * for every submitted line counted minus expected is the variance, exactly, and expected is the balance at snapshot_at; a submit
 * writes no movement; at most one unresolved count per place (rule R-A); an approved count has no variance and a pending one has
 * some; for every opening line with a carried figure the expected figure is carried plus what moved since the previous closing
 * (no opening_drift is ever logged); movements, balances and the view agree (the Phase 0 oracle). 15 seeds, at most 40 operations
 * each; the seed is in the dataset name and in every failure message; SHIFTS_SEED=<n> replays one.
 */

/** @return array<string, array{0: int}> */
function countsSeeds(): array
{
    $one = getenv('SHIFTS_SEED');
    $seeds = is_string($one) && ctype_digit($one) ? [(int) $one] : array_map(fn (int $i): int => 4301 + $i * 6007, range(1, 15));

    return array_combine(array_map(fn (int $s): string => "seed {$s}", $seeds), array_map(fn (int $s): array => [$s], $seeds));
}

it('keeps counted minus expected exact and at most one unresolved count per place, whatever the sequence', function (int $seed) {
    $rng = new Randomizer(new Mt19937($seed));
    $branch = actInBranch(Branch::factory()->create());
    $f = ShiftsFixture::build($branch, $this);
    $places = [$f->place($f->kitchen), $f->place($f->kitchen, LocationType::Bar)];
    $items = [$f->ingredient(), $f->ingredient(), $f->ingredient('ml')];
    $clock = CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC');
    $this->travelTo($clock);
    $shifts = fn (): Shifts => app(Shifts::class);
    $counts = fn (): Counts => app(Counts::class);
    $decimal = fn (int $max): string => (string) BigDecimal::of($rng->getInt(0, $max))->dividedBy(10000, 4);
    $trail = [];
    $fail = fn (string $what): string => "seed {$seed}: {$what}\n".implode("\n", $trail);

    foreach ($items as $item) {
        foreach ($places as $place) {
            $f->stock($item, $place, $decimal(900000));
        }
    }

    $submitAttempted = false;
    $step = function (int $i) use (&$trail, &$submitAttempted, $f, $places, $items, $rng, $decimal, $shifts, $counts, &$clock): void {
        $this->travelTo($clock = $clock->addSeconds($rng->getInt(2, 5)));
        withActor($f->admin->id);
        $choice = $rng->getInt(1, 6);
        $note = "op {$i} at {$clock->format('H:i:s')}: ";
        $running = $shifts()->current($f->kitchen);

        if ($choice <= 2) {
            $item = $items[$rng->getInt(0, 2)];
            $place = $places[$rng->getInt(0, 1)];
            $onHand = BigDecimal::of((string) (stockRow($item, $place)->on_hand ?? '0'));
            if ($rng->getInt(0, 1) === 0 || $onHand->isZero()) {
                $qty = $decimal(500000);
                $f->stock($item, $place, $qty);
                $note .= "stock in {$qty}";
            } else {
                $qty = (string) $onHand->multipliedBy($rng->getInt(1, 100))->dividedBy(100, 4, RoundingMode::Down);
                $f->stock($item, $place, '-'.$qty, MovementReason::Waste);
                $note .= "waste {$qty}";
            }
        } elseif ($choice === 3) {
            if ($running === null) {
                $carry = $rng->getInt(1, 10) <= 3;
                $outcome = $shifts()->start($f->kitchen, (string) Str::uuid(), carry: $carry);
                $note .= 'start '.($carry ? 'carried' : 'counted').' -> '.$outcome->shift->status->value;
            } elseif ($running->status === ShiftStatus::Open) {
                $shifts()->close($running);
                $note .= 'close';
            } else {
                $note .= "shift stays {$running->status->value}";
            }
        } else {
            $drafts = StockCount::query()->where('status', CountStatus::Draft->value)
                ->whereIn('shift_instance_id', ShiftInstance::query()->where('open_key', 1)->pluck('id'))->orderBy('id')->get();
            if ($drafts->isEmpty()) {
                $note .= 'no draft to work on';
            } else {
                $count = $drafts[$rng->getInt(0, $drafts->count() - 1)];
                if ($choice <= 5) {
                    // enter: everything at its prefill, then a few lines changed with a reason
                    $lines = [];
                    foreach ($count->lines()->get() as $line) {
                        if ($rng->getInt(1, 10) <= 4) {
                            $lines[] = ['item_id' => $line->item_id, 'counted_qty' => $decimal(900000), 'reason_id' => $f->reasonId('miscount')];
                        }
                    }
                    $counts()->setLines($count, $lines, confirmAll: true);
                    $note .= "enter {$count->type->value} count {$count->id} (".count($lines).' changed)';
                } else {
                    $submitAttempted = true;
                    try {
                        $submitted = $counts()->submit($count);
                        $note .= "submit {$count->type->value} count {$count->id} -> {$submitted->status->value}";
                    } catch (ShiftError $e) {
                        $note .= "submit {$count->type->value} count {$count->id} refused: {$e->messageKey}";
                        if ($e->messageKey === 'shifts.count.blocked_by_pending') {
                            $other = StockCount::query()->findOrFail($e->extra['count_id']);
                            $note .= " (by {$other->id} at place {$other->location_id})";
                            $current = $shifts()->current($f->kitchen);
                            if ($current?->status === ShiftStatus::Opening) {
                                $shifts()->cancel($current, 'blocked by an earlier count');
                                $note .= '; opening cancelled';
                            }
                        }
                    }
                }
            }
        }
        $trail[] = $note;
    };

    $operations = $rng->getInt(15, 40);
    for ($i = 1; $i <= $operations; $i++) {
        $before = DB::table('stock_movements')->count();
        $submitAttempted = false;
        $step($i);
        if ($submitAttempted) {
            expect(DB::table('stock_movements')->count())->toBe($before, $fail('a submit wrote a movement'));
        }

        // R-A: at most one unresolved count per place
        $unresolved = StockCount::query()->whereIn('status', [CountStatus::AwaitingCosign->value, CountStatus::PendingApproval->value])
            ->selectRaw('location_id, count(*) as n')->groupBy('location_id')->get();
        foreach ($unresolved as $row) {
            expect((int) $row->n)->toBeLessThanOrEqual(1, $fail("place {$row->location_id} has {$row->n} unresolved counts"));
        }

        // the variance of every submitted line, and the expected figure against the balance at the snapshot
        foreach (StockCount::query()->whereIn('status', array_map(fn (CountStatus $s): string => $s->value, array_filter(CountStatus::cases(), fn (CountStatus $s): bool => $s->isSubmitted())))->get() as $count) {
            $differs = false;
            foreach ($count->lines as $line) {
                expect($line->expected_qty)->not->toBeNull($fail("count {$count->id} line {$line->id} has no expected figure"));
                $variance = BigDecimal::of($line->counted_qty->toString())->minus($line->expected_qty->toString());
                expect($line->variance_qty->toString())->toBe((string) $variance->toScale(4), $fail("count {$count->id} line {$line->id} variance"));
                $atSnapshot = app(Stock::class)->balanceAt($line->item_id, $count->location_id, CarbonImmutable::parse($count->snapshot_at));
                expect($line->expected_qty->toString())->toBe($atSnapshot->onHand->toString(), $fail("count {$count->id} line {$line->id} expected against the balance at snapshot_at"));
                $differs = $differs || ! $variance->isZero();
            }
            if (in_array($count->status, [CountStatus::Approved, CountStatus::Locked], true) && $count->decision === null) {
                expect($differs)->toBeFalse($fail("approved count {$count->id} has a variance"));
            }
            if ($count->status === CountStatus::PendingApproval) {
                expect($differs)->toBeTrue($fail("pending count {$count->id} has none"));
            }
        }
        expect(DB::table('audit_log')->where('action', 'count.opening_drift')->count())->toBe(0, $fail('an opening drift was logged'));
        expect(DB::table('stock_movements')->where('reason', 'count_adjustment')->count())->toBe(0, $fail('a count wrote an adjustment'));
        $oracle = ledgerOracle($branch->id);
        expect($oracle['balances'])->toBe($oracle['movements'], $fail('balances against movements'))->and($oracle['view'])->toBe($oracle['movements'], $fail('view against movements'));
    }
})->with(countsSeeds());

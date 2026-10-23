<?php

declare(strict_types=1);

use App\Modules\Shifts\Contracts\HashChain;
use App\Modules\Shifts\Models\CashDeclaration;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'property');

/*
 * Step 3.5 (phase-3 plan section 7, CashPropertyTest): a till that works several shifts in a row with random money. Whatever the sequence:
 *   - an opening count expects the float the last closing left (the very first expects what it counted);
 *   - a closing count expects the opening count's counted total plus the cash taken in the shift (amount, never tender or change; voided
 *     payments and other shifts' payments left out);
 *   - variance = counted - expected, exactly, and counted is the denominations x counts, worked out here by an independent sum;
 *   - a variance of zero locks at once, any other waits for the manager and is explained;
 *   - the chain of the drawer verifies after every shift.
 * 12 seeds, 4 shifts each; the seed is in the dataset name and every failure message; SHIFTS_SEED=<n> replays one.
 */

/** @return array<string, array{0: int}> */
function cashSeeds(): array
{
    $one = getenv('SHIFTS_SEED');
    $seeds = is_string($one) && ctype_digit($one) ? [(int) $one] : array_map(fn (int $i): int => 7001 + $i * 3877, range(1, 12));

    return array_combine(array_map(fn (int $s): string => "seed {$s}", $seeds), array_map(fn (int $s): array => [$s], $seeds));
}

it('keeps expected = float + cash taken and variance = counted - expected, whatever the sequence', function (int $seed) {
    $rng = new Randomizer(new Mt19937($seed));
    $branch = actInBranch(Branch::factory()->create());
    $f = ShiftsFixture::build($branch, $this);
    $drawer = $f->drawer($f->till);
    $elsewhere = $f->running($f->kitchen, $f->chef);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
    $values = ['200', '100', '50', '10', '5', '1', '0.50', '0.25', '0.10', '0.05'];
    $trail = [];
    $fail = fn (string $what): string => "seed {$seed}: {$what}\n".implode("\n", $trail);
    $draw = function (int $max) use ($rng, $values): array {
        $counts = [];
        foreach ($values as $v) {
            $counts[$v] = $rng->getInt(0, $max);
        }

        return $counts;
    };
    $sum = function (array $counts): string {
        $total = BigDecimal::zero();
        foreach ($counts as $v => $n) {
            $total = $total->plus(BigDecimal::of((string) $v)->multipliedBy($n));
        }

        return (string) $total->toScale(2);
    };

    $left = null; // the float the last closing left: null before the first closing
    for ($day = 1; $day <= 4; $day++) {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC')->addDays($day));

        // opening: sometimes exactly the float, sometimes a different drawer
        $start = $f->start($f->cashier, $f->till)->assertCreated();
        $shift = (int) $start->json('shift.id');
        $open = (int) collect($start->json('counts'))->firstWhere('location.type', 'cash_drawer')['id'];
        $counted = $draw(3);
        $openCounted = $sum($counted);
        $expected = $left ?? $openCounted;
        $variance = (string) BigDecimal::of($openCounted)->minus($expected)->toScale(2);
        $trail[] = "day {$day} opening: counted {$openCounted} expected {$expected}";
        $reason = $variance === '0.00' ? [] : ['reason_id' => $f->reasonId('miscount')];
        $f->cashPut($f->cashier, $open, ['denominations' => $counted] + $reason)->assertOk();
        $submit = $f->submit($f->cashier, $open)->assertOk();
        expect($submit->json('cash.counted_total'))->toBe($openCounted, $fail('opening counted'))
            ->and($submit->json('cash.expected_total'))->toBe($expected, $fail('opening expected'))
            ->and($submit->json('cash.variance_total'))->toBe($variance, $fail('opening variance'))
            ->and($submit->json('status'))->toBe($variance === '0.00' ? 'locked' : 'pending_approval', $fail('opening status'));
        if ($variance !== '0.00') {
            $f->approve($f->manager, $open)->assertOk();
        }

        // the shift's money: payments with tender and change, some voided, some of another shift
        $taken = BigDecimal::zero();
        $n = $rng->getInt(0, 6);
        for ($i = 0; $i < $n; $i++) {
            $amount = (string) BigDecimal::of($rng->getInt(1, 100000))->dividedBy(100, 2);
            $tender = $rng->getInt(0, 1) === 1 ? (string) BigDecimal::of($amount)->plus(BigDecimal::of($rng->getInt(0, 5000))->dividedBy(100, 2))->toScale(2) : null;
            $voided = $rng->getInt(0, 7) === 0;
            $f->taken($shift, $amount, $tender, 'cash', $voided ? ['status' => 'voided'] : []);
            if (! $voided) {
                $taken = $taken->plus($amount);
            }
            if ($rng->getInt(0, 3) === 0) {
                $f->taken($elsewhere, (string) BigDecimal::of($rng->getInt(1, 5000))->dividedBy(100, 2));
            }
        }
        $sales = (string) $taken->toScale(2);

        $closing = $f->closeTill($shift, $f->cashier);
        $counted = $draw(8);
        $closeCounted = $sum($counted);
        $expected = (string) BigDecimal::of($openCounted)->plus($sales)->toScale(2);
        $variance = (string) BigDecimal::of($closeCounted)->minus($expected)->toScale(2);
        $floatLeft = $closeCounted === '0.00' ? '0.00' : (string) BigDecimal::of($rng->getInt(0, (int) (string) BigDecimal::of($closeCounted)->multipliedBy(100)->toScale(0)))->dividedBy(100, 2);
        $trail[] = "day {$day} closing: counted {$closeCounted} expected {$expected} sales {$sales} variance {$variance} float {$floatLeft}";
        $reason = $variance === '0.00' ? [] : ['reason_id' => $f->reasonId('miscount')];
        $f->cashPut($f->cashier, $closing['count'], ['denominations' => $counted, 'float_left_total' => $floatLeft] + $reason)->assertOk();
        $submit = $f->submit($f->cashier, $closing['count'])->assertOk();
        expect($submit->json('cash.cash_sales_total'))->toBe($sales, $fail('cash sales'))
            ->and($submit->json('cash.expected_total'))->toBe($expected, $fail('closing expected'))
            ->and($submit->json('cash.counted_total'))->toBe($closeCounted, $fail('closing counted'))
            ->and($submit->json('cash.variance_total'))->toBe($variance, $fail('closing variance'))
            ->and($submit->json('status'))->toBe($variance === '0.00' ? 'locked' : 'pending_approval', $fail('closing status'));
        if ($variance !== '0.00') {
            $f->approve($f->manager, $closing['count'])->assertOk();
        }
        expect(ShiftInstance::query()->findOrFail($shift)->status->value)->toBe('locked', $fail('the shift locks'))
            ->and(CashDeclaration::query()->where('count_id', $closing['count'])->sole()->float_left_total?->amount())->toBe($floatLeft, $fail('float stored'))
            ->and(app(HashChain::class)->verify($drawer->id)->toArray()['ok'])->toBeTrue($fail('chain'));
        $left = $floatLeft;
    }

    expect(StockCount::query()->where('location_id', $drawer->id)->count())->toBe(8, $fail('eight counts'));
})->with(cashSeeds());

<?php

declare(strict_types=1);

namespace Database\Factories\Shifts;

use App\Modules\Catalog\Models\Item;
use App\Modules\Shifts\Enums\ReasonStatus;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\StockCountLine;
use App\Shared\Models\Branch;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * An untouched line of a count: no figure, not confirmed, reason status `none`. forCount() puts it on a count and an item;
 * counted() gives it a counted figure (and an expected one), confirmed, with the variance worked out in exact decimals and the
 * reason status `pending` when it differs. A row only: nothing goes through the actions. The freeze trigger refuses an insert under
 * a count that is not a draft, as it must in production, so a fixture that puts lines under a count already made `approved` or
 * `pending_approval` (the history a test needs) has the factory hold the count at `draft` for the insert and put its status back:
 * the end state is the one the actions leave (lines made while a draft, then submitted). A `locked` count cannot be held that way
 * (the lock columns are tied to the status): make its lines first.
 *
 * @extends Factory<StockCountLine>
 */
final class StockCountLineFactory extends Factory
{
    protected $model = StockCountLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'count_id' => fn (array $attributes): int => StockCount::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'item_id' => fn (array $attributes): int => Item::factory()->ingredient('g')->create(['branch_id' => $attributes['branch_id']])->id,
            'expected_qty' => null,
            'carried_qty' => null,
            'counted_qty' => null,
            'variance_qty' => null,
            'confirmed_at' => null,
            'variance_reason_id' => null,
            'variance_note' => null,
            'reason_status' => ReasonStatus::None,
        ];
    }

    public function configure(): static
    {
        /** @var array<int, string> $held the status each parent had before it was held at draft */
        $held = [];

        return $this
            ->afterMaking(function (StockCountLine $line) use (&$held): void {
                $status = DB::table('stock_counts')->where('id', $line->count_id)->value('status');
                if (is_string($status) && $status !== 'draft') {
                    DB::table('stock_counts')->where('id', $line->count_id)->update(['status' => 'draft']);
                    $held[$line->count_id] = $status;
                }
            })
            ->afterCreating(function (StockCountLine $line) use (&$held): void {
                if (isset($held[$line->count_id])) {
                    DB::table('stock_counts')->where('id', $line->count_id)->update(['status' => $held[$line->count_id]]);
                    unset($held[$line->count_id]);
                }
            });
    }

    public function forCount(StockCount $count, int $itemId): self
    {
        return $this->state(fn (): array => ['branch_id' => $count->branch_id, 'count_id' => $count->id, 'item_id' => $itemId]);
    }

    /** Counted (and confirmed) at $counted against an expected figure; the variance follows. */
    public function counted(string $counted, string $expected): self
    {
        return $this->state(function () use ($counted, $expected): array {
            $variance = Quantity::of($counted)->minus($expected);

            return [
                'expected_qty' => $expected,
                'counted_qty' => $counted,
                'variance_qty' => $variance,
                'confirmed_at' => CarbonImmutable::now()->startOfSecond(),
                'reason_status' => $variance->isZero() ? ReasonStatus::None : ReasonStatus::Pending,
            ];
        });
    }

    public function carried(string $carried): self
    {
        return $this->state(fn (): array => ['carried_qty' => $carried]);
    }
}

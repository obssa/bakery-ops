<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Views;

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\StockLevel;
use App\Modules\Inventory\Ledger\LedgerReader;
use App\Modules\Inventory\Models\ReorderRule;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;

/**
 * The purchasing list (phase-2 plan section 4, step 2.6b): one row per live, active reorder rule whose figure is at or below its
 * minimum. The figure of a rule on a place is that place's available; of a branch-wide rule the sum of available over the
 * branch's stock places (never the cash drawer), the same numbers the alert evaluation uses. state: out (0 or below) or low;
 * suggested_qty is the rule's reorder quantity. consumption_7d is what left the item at any place in the last 7 local days (today
 * and the six before it) by production, sale and waste; days_of_cover = available / (consumption_7d / 7), null with no
 * consumption. Out first, then by available over minimum. A fixed number of queries however many rules.
 */
final class ReorderList
{
    public const int WINDOW_DAYS = 7;

    public function __construct(private readonly LedgerReader $reader, private readonly CurrentBranch $branch) {}

    /**
     * @param  int|null  $locationKey  only the rules of this place; 0 for the branch-wide rules; null for all
     * @return array{data: list<array<string, mixed>>, generated_at: string}
     */
    public function build(?int $locationKey): array
    {
        $now = CarbonImmutable::now();
        $rules = ReorderRule::query()
            ->where('is_active', true)
            ->when($locationKey !== null, fn ($q) => $q->where('location_key', $locationKey))
            ->with(['item.unit.baseUnit', 'location'])
            ->orderBy('id')
            ->get()
            ->filter(fn (ReorderRule $rule): bool => $rule->getRelation('item') instanceof Item); // an item deleted since is not bought

        $levels = $this->reader->levelsOfItems(array_values(array_unique($rules->pluck('item_id')->all())));

        $due = [];
        foreach ($rules as $rule) {
            $available = $this->available($rule, $levels[$rule->item_id] ?? []);
            if ($available->isLessThanOrEqualTo($rule->min_qty)) {
                $due[] = [$rule, $available];
            }
        }

        $from = $now->setTimezone($this->branch->timezone())->startOfDay()->subDays(self::WINDOW_DAYS - 1);
        $used = $this->reader->consumedSince($from, array_values(array_unique(array_map(static fn (array $d): int => $d[0]->item_id, $due))));

        $lines = [];
        foreach ($due as [$rule, $available]) {
            $item = $rule->item;
            $place = $rule->location;
            $consumed = Quantity::of($used[$item->id] ?? '0');
            $out = ! $available->isPositive();
            $lines[] = [
                'rank' => $out ? 0 : 1,
                'ratio' => $rule->min_qty->isPositive() ? $available->dividedBy($rule->min_qty) : Quantity::zero(),
                'id' => $rule->id,
                'row' => [
                    'item' => ['id' => $item->id, 'name_en' => $item->name_en, 'name_am' => $item->name_am, 'unit' => ($item->unit->baseUnit ?? $item->unit)->code],
                    'location' => $place === null ? null : ['id' => $place->id, 'name_en' => $place->name_en, 'name_am' => $place->name_am, 'type' => $place->type->value],
                    'available' => $available->toString(),
                    'min_qty' => $rule->min_qty->toString(),
                    'reorder_qty' => $rule->reorder_qty->toString(),
                    'consumption_7d' => $consumed->toString(),
                    'days_of_cover' => $consumed->isPositive() ? $available->multipliedBy(self::WINDOW_DAYS)->dividedBy($consumed)->toString() : null,
                    'suggested_qty' => $rule->reorder_qty->toString(),
                    'state' => $out ? 'out' : 'low',
                ],
            ];
        }
        usort($lines, static function (array $a, array $b): int {
            if ($a['rank'] !== $b['rank']) {
                return $a['rank'] <=> $b['rank'];
            }
            $byRatio = $a['ratio']->compareTo($b['ratio']);

            return $byRatio !== 0 ? $byRatio : $a['id'] <=> $b['id'];
        });

        return ['data' => array_column($lines, 'row'), 'generated_at' => $now->toIso8601String()];
    }

    /**
     * @param  list<StockLevel>  $levels  the item's balances at the branch's stock places
     */
    private function available(ReorderRule $rule, array $levels): Quantity
    {
        $sum = Quantity::zero();
        foreach ($levels as $level) {
            if ($rule->location_key === 0 || $level->locationId === $rule->location_key) {
                $sum = $sum->plus($level->available());
            }
        }

        return $sum;
    }
}

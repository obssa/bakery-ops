<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemPrice;
use App\Modules\Catalog\Models\ItemStationAssignment;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * manifest catalog.provides: "Items::isNew(item)" and the kind / fulfilment every consumer branches on.
 * isNew: new up to and including is_new_until, a date in the branch's own calendar (Africa/Addis_Ababa), so the New
 * row on the pads empties at local midnight.
 */
final class Items
{
    public function __construct(private readonly CurrentBranch $branch) {}

    public function isNew(Item|int $item, ?DateTimeInterface $at = null): bool
    {
        $item = $this->item($item);
        if ($item->is_new_until === null) {
            return false;
        }
        $today = CarbonImmutable::instance($at ?? CarbonImmutable::now())->setTimezone($this->timezoneOf($item))->toDateString();

        return $today <= $item->is_new_until->toDateString();
    }

    public function kind(Item|int $item): ItemKind
    {
        return $this->item($item)->kind;
    }

    public function fulfilment(Item|int $item): Fulfilment
    {
        return $this->item($item)->fulfilment;
    }

    private function timezoneOf(Item $item): string
    {
        if ($this->branch->idOrNull() === $item->branch_id) {
            return $this->branch->timezone();
        }
        $timezone = $item->branch()->value('timezone') ?? config('bakery.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Africa/Addis_Ababa';
    }

    private function item(Item|int $item): Item
    {
        return $item instanceof Item ? $item : Item::query()->findOrFail($item);
    }

    /**
     * What the pad's menu is computed from, on the Catalog side, in a few cheap aggregate queries (GET /menu's ETag, with
     * Inventory's Stock::fingerprint): how many rows each table behind the menu has (deleted ones included) and when
     * the latest was written (updated_at, whole seconds), the prices in force at $at (a price taking effect or ending
     * changes the menu with no row written), and the branch's calendar day (is_new rolls over at local midnight).
     *
     * @return array<string, int|string|null>
     */
    public function menuFingerprint(?DateTimeInterface $at = null): array
    {
        $at = CarbonImmutable::instance($at ?? CarbonImmutable::now());
        $touched = static function (Builder $query): array {
            $row = $query->toBase()->selectRaw('count(*) as n, max(updated_at) as touched')->first();

            $latest = $row?->touched;

            return [(int) ($row?->n), is_string($latest) ? $latest : null];
        };

        $parts = ['day' => $at->setTimezone($this->branch->timezone())->toDateString()];
        foreach ([
            'items' => Item::query()->withTrashed()->where('kind', ItemKind::Product->value),
            'categories' => Category::query()->withTrashed(),
            'modifiers' => Modifier::query()->withTrashed(),
            'stations' => ItemStationAssignment::query()->withTrashed(),
            'recipes' => Recipe::query()->withTrashed(),
            'recipe_lines' => RecipeLine::query()->withTrashed(),
        ] as $name => $query) {
            [$parts[$name.'_n'], $parts[$name.'_at']] = $touched($query);
        }

        $instant = $at->utc();
        $prices = ItemPrice::query()
            ->where('effective_from', '<=', $instant)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $instant))
            ->toBase()->selectRaw('count(*) as n, max(id) as top')->first();
        $top = $prices?->top;
        $parts['prices_n'] = (int) ($prices?->n);
        $parts['prices_top'] = is_numeric($top) ? (int) $top : null;

        return $parts;
    }
}

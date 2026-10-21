<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemPrice;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Values\Quantity;
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
    public function __construct(private readonly CurrentBranch $branch, private readonly Recipes $recipes) {}

    public function isNew(Item|int $item, ?DateTimeInterface $at = null): bool
    {
        $item = $this->item($item);
        if ($item->is_new_until === null) {
            return false;
        }
        $today = CarbonImmutable::instance($at ?? CarbonImmutable::now())->setTimezone($this->timezoneOf($item))->toDateString();

        return $today <= $item->is_new_until->toDateString();
    }

    /**
     * The batch size Production proposes for a product, in its base unit (an automatic request after a stock-out, the
     * default on a card): the stored default_batch_qty when it is positive, else the active recipe's yield in base units,
     * else null. Null for what is not produced (an ingredient, a bought-in product) and for an id this branch does not
     * know, so a caller can skip it quietly; never zero or negative.
     */
    public function batchQty(Item|int $product): ?Quantity
    {
        $item = $product instanceof Item ? $product : Item::query()->find($product);
        if ($item === null || ! $item->isProduct() || $item->isBoughtIn()) {
            return null;
        }
        if ($item->default_batch_qty !== null && $item->default_batch_qty->isPositive()) {
            return $item->default_batch_qty;
        }
        $view = $this->recipes->activeFor($item);

        return $view !== null && $view->recipeId !== null && $view->yield->isPositive() ? $view->yield : null;
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
     * What the pad's menu is computed from, on the Catalog side, in two cheap queries (GET /menu's ETag, with
     * Inventory's Stock::fingerprint): the branch's catalog_version, a counter every write to the catalog tables behind
     * the menu bumps (Catalog's BumpsCatalogVersion), so two edits inside one second still change the tag where
     * whole-second updated_at stamps would not; the prices in force at $at (a price taking effect or ending changes the
     * menu with no row written); and the branch's calendar day (is_new rolls over at local midnight).
     *
     * @return array<string, int|string|null>
     */
    public function menuFingerprint(?DateTimeInterface $at = null): array
    {
        $at = CarbonImmutable::instance($at ?? CarbonImmutable::now());
        $version = Branch::query()->whereKey($this->branch->id())->toBase()->value('catalog_version');

        $parts = [
            'day' => $at->setTimezone($this->branch->timezone())->toDateString(),
            'catalog_version' => is_numeric($version) ? (int) $version : 0,
        ];

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

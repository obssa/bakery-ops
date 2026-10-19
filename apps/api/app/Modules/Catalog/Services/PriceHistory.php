<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Exceptions\CatalogError;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemPrice;
use App\Shared\Actor\ActorContext;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;

/**
 * The single writer of item_prices, called inside the caller's AuditedAction (POST /items/{item}/prices). History
 * only moves forward: the new price must start after the open one, which it closes at its own effective_from. The
 * item row is locked first, so two price changes for one item cannot both see the same open row.
 */
final class PriceHistory
{
    public function __construct(private readonly ActorContext $actor) {}

    /**
     * @throws CatalogError catalog.price.too_early (422)
     */
    public function open(Item $item, Money $price, CarbonImmutable $from): ItemPrice
    {
        $from = $from->utc()->startOfSecond();
        Item::query()->whereKey($item->id)->lockForUpdate()->first();

        $open = ItemPrice::query()
            ->where('item_id', $item->id)
            ->whereNull('effective_to')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($open !== null) {
            if ($from->lessThanOrEqualTo($open->effective_from)) {
                throw CatalogError::priceTooEarly($open->effective_from);
            }
            $open->forceFill(['effective_to' => $from])->save();
        }

        $row = new ItemPrice;
        $row->forceFill([
            'branch_id' => $item->branch_id,
            'item_id' => $item->id,
            'price' => $price,
            'currency' => $price->currency(),
            'effective_from' => $from,
            'effective_to' => null,
        ] + $this->actor->stamp())->save();

        return $row;
    }
}

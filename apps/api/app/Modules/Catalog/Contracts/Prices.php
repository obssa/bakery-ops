<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemPrice;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * manifest catalog.provides: "Prices::at(item, at) -> Money". The price effective at an instant: effective_from
 * inclusive, effective_to exclusive; null before the first price. Instants are compared in UTC, whatever timezone $at
 * carries (rows are stored in UTC). Orders snapshot Prices::at(sent_at) on every line (Phase 1).
 */
final class Prices
{
    public function at(Item|int $item, DateTimeInterface $at): ?Money
    {
        return $this->rowAt($item, $at)?->price;
    }

    public function current(Item|int $item): ?Money
    {
        return $this->at($item, CarbonImmutable::now());
    }

    public function rowAt(Item|int $item, DateTimeInterface $at): ?ItemPrice
    {
        $instant = CarbonImmutable::instance($at)->utc();

        return ItemPrice::query()
            ->where('item_id', $item instanceof Item ? $item->id : $item)
            ->where('effective_from', '<=', $instant)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $instant))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }
}

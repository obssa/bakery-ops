<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Item;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use DateTimeInterface;

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
}

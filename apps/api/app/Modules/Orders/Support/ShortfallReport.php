<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Shortfall;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Models\Station;
use App\Shared\Values\Quantity;

/**
 * The body of 409 orders.send.shortfall (phase-1 plan section 4; workflows-and-screens 4.1): one entry per short
 * ingredient of a short line, in the order the lines were sent (Inventory's Shortfall names each shortage's ref, which
 * is the line's reservation ref). Each entry names the line (client UUID, id, product, quantity), the ingredient, what
 * was needed and what was left for this line at its location, the quantity short as a decimal string in the
 * ingredient's base unit with that unit, the same quantity as people read it ("120 g"), the station working from that
 * location, and the sentence the pad shows on the line, in English and Amharic:
 *   "Cannot add Chicken sandwich ×2: Chicken breast short by 120 g (Kitchen)."
 */
final class ShortfallReport
{
    /**
     * @param  array<string, OrderLine>  $linesByRef  the order's lines by reservation ref
     * @return list<array<string, int|string|null>>
     */
    public function lines(Shortfall $shortfall, array $linesByRef): array
    {
        $itemIds = array_map(static fn (OrderLine $line): int => $line->item_id, array_values($linesByRef));
        $stationIds = [];
        foreach ($shortfall->shortages as $shortage) {
            $itemIds[] = $shortage->itemId;
            if ($shortage->stationId !== null) {
                $stationIds[] = $shortage->stationId;
            }
        }
        $items = Item::query()->withTrashed()->whereKey(array_values(array_unique($itemIds)))->get()->keyBy('id');
        $stations = Station::query()->withTrashed()->whereKey(array_values(array_unique($stationIds)))->get()->keyBy('id');

        $report = [];
        foreach ($shortfall->shortages as $shortage) {
            $line = $shortage->ref === null ? null : ($linesByRef[$shortage->ref] ?? null);
            if ($line === null) {
                continue; // every shortage of reserveMany carries the ref of its line
            }
            $product = $items->get($line->item_id);
            $ingredient = $items->get($shortage->itemId);
            $station = $shortage->stationId === null ? null : $stations->get($shortage->stationId);
            $short = self::plain($shortage->qtyShort).' '.$shortage->unit;
            $sentence = function (string $locale) use ($product, $ingredient, $station, $line, $short): string {
                $key = $station instanceof Station ? 'orders.send.shortfall_line' : 'orders.send.shortfall_line_no_station';
                $text = trans($key, [
                    'item' => (string) $product?->getAttribute('name_'.$locale),
                    'qty' => self::plain($line->qty),
                    'ingredient' => (string) $ingredient?->getAttribute('name_'.$locale),
                    'short' => $short,
                    'station' => (string) $station?->getAttribute('name_'.$locale),
                ], $locale);

                return is_string($text) ? $text : $key;
            };

            $report[] = [
                'line_client_uuid' => $line->client_uuid,
                'line_id' => $line->id,
                'item_id' => $line->item_id,
                'item_name_en' => $product?->name_en,
                'item_name_am' => $product?->name_am,
                'qty' => $line->qty->toString(),
                'ingredient_item_id' => $shortage->itemId,
                'ingredient_name_en' => $ingredient?->name_en,
                'ingredient_name_am' => $ingredient?->name_am,
                'needed' => $shortage->needed->toString(),
                'available' => $shortage->available->toString(),
                'qty_short' => $shortage->qtyShort->toString(),
                'unit' => $shortage->unit,
                'qty_short_display' => $short,
                'location_id' => $shortage->locationId,
                'station_id' => $shortage->stationId,
                'station_name_en' => $station?->name_en,
                'station_name_am' => $station?->name_am,
                'message_en' => $sentence('en'),
                'message_am' => $sentence('am'),
            ];
        }

        return $report;
    }

    /** A quantity as people read it: "120.0000" is "120", "0.5000" is "0.5" (decimal, never a float). */
    private static function plain(Quantity $qty): string
    {
        return $qty->toBigDecimal()->strippedOfTrailingZeros()->toString();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Models\Item;

/**
 * What a refund may do with each product (step 4.1b; Ramin's answer to R-C, 2026-10-28: "the admin should decide"; contract orders->catalog).
 * Orders asks it at the moment of a refund and never reads Catalog's tables itself: `refundable` says whether a line of the product can be
 * refunded at all, `restock` whether a refunded unit goes back into stock (items.refund_restock; Catalog keeps it false for what cannot go
 * back: a product made to order, an ingredient). A product deleted since its sale is answered with the settings it was left with; another
 * branch's ids and unknown ids are left out. One query, keyed and ordered by item id. Resolved per use (CurrentBranch through the model).
 */
final class RefundPolicy
{
    /**
     * @param  list<int>  $itemIds
     * @return array<int, RefundRule>
     */
    public function for(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $rules = [];
        $rows = Item::query()->withTrashed()->whereKey(array_values(array_unique($itemIds)))->orderBy('id')->get(['id', 'refundable', 'refund_restock']);
        foreach ($rows as $item) {
            $rules[$item->id] = new RefundRule($item->id, (bool) $item->refundable, (bool) $item->refund_restock);
        }

        return $rules;
    }
}

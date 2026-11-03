<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemImage;
use App\Shared\Tenancy\BranchScope;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The display photo of each product (step 6.1a, phase-6 plan section 2; manifest catalog.provides; contract orders->catalog). Orders'
 * MenuBuilder (6.1b) asks it once per menu build and never reads Catalog's tables itself.
 *
 * for(ids): item id => ItemImageView for an item with a current photo, null for one without (none yet, or removed); keyed and ordered by
 * id; another branch's ids and unknown ids are left out; a deleted item is answered like the others. One query whatever the number of ids:
 * the items of the branch, left-joined to their live item_images row. Resolved per use (CurrentBranch through the model's scope).
 *
 * liveBytes() (step 6.1b, for the System page's files.media_used_mb): the bytes the live photos of every branch take on the disk, from
 * item_images.bytes (the three renditions together); a photo two products share is one set of files, so it counts once. A replaced or
 * removed photo is not counted, though its files stay until the prune (bakery:prune-product-images). One query.
 */
final class ItemImages
{
    /**
     * @param  list<int>  $itemIds
     * @return array<int, ItemImageView|null>
     */
    public function for(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $rows = Item::query()->withTrashed()->toBase()
            ->leftJoin('item_images as image', function (JoinClause $join): void {
                $join->on('image.id', '=', 'items.image_id')->whereNull('image.deleted_at');
            })
            ->whereIn('items.id', array_values(array_unique($itemIds)))
            ->orderBy('items.id')
            ->get(['items.id', 'image.sha256', 'image.mime', 'image.width', 'image.height']);

        $images = [];
        foreach ($rows as $row) {
            $images[(int) $row->id] = $row->sha256 === null
                ? null
                : ItemImageView::of((string) $row->sha256, (string) $row->mime, (int) $row->width, (int) $row->height);
        }

        return $images;
    }

    public function liveBytes(): int
    {
        // one disk holds every branch's photos, so the figure is the host's: the branch scope is lifted on purpose
        $photos = ItemImage::query()->withoutGlobalScope(BranchScope::class)->toBase()
            ->select('sha256')->selectRaw('MAX(bytes) AS bytes')->groupBy('sha256');
        return (int) DB::query()->fromSub($photos, 'photos')->sum('bytes'); // whole bytes: SUM of an integer column
    }
}

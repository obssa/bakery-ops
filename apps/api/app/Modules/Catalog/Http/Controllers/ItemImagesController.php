<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\StoreItemImageRequest;
use App\Modules\Catalog\Http\Resources\ItemImagePayload;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Services\ProductPhotos;
use Illuminate\Http\JsonResponse;

/**
 * The display photo of a product (step 6.1a, phase-6 plan 4.1, ADR 0022; catalog.manage, the permission for editing the item, so the
 * admin only). Both writes carry an Idempotency-Key (rule 7): a replay answers the stored response and writes nothing. {item} binds
 * through the BranchScoped model, so an unknown, deleted or other-branch item is 404.
 *
 *   POST   /items/{item}/image  201 {data: {item_id, image: {id, thumb, card, large, width, height, origin, uploaded_at, uploaded_by}}};
 *                               200 with the same body for a client_uuid already stored (multipart: photo, crop_*, client_uuid)
 *   DELETE /items/{item}/image  200 {data: {item_id, image: null}}, also when the product has no photo (then nothing is written)
 */
final class ItemImagesController
{
    public function __construct(private readonly ProductPhotos $photos) {}

    public function store(StoreItemImageRequest $request, Item $item): JsonResponse
    {
        $image = $this->photos->set($item, $request->photoBytes(), $request->crop(), $request->clientUuid());

        return response()->json(
            ['data' => ['item_id' => $image->item_id, 'image' => ItemImagePayload::detail($image)]],
            $image->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function destroy(Item $item): JsonResponse
    {
        $this->photos->remove($item);

        return response()->json(['data' => ['item_id' => $item->id, 'image' => null]]);
    }
}

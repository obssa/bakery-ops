<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Contracts\ItemImageView;
use App\Modules\Catalog\Models\ItemImage;
use App\Shared\Contracts\ActorNames;

/**
 * The `image` of an item payload (step 6.1a, phase-6 plan 4.3). summary(): {thumb, card, large, width, height}, what GET /items gives
 * every item (the same shape as ItemImageView, which the menu gives in 6.1b). detail(): the summary plus id, origin, uploaded_at and
 * uploaded_by {id, name} (null for a demo photo), for GET /items/{item} and the upload's answer: the photo card's "Added by ... · date
 * · W x H" line. The name comes through Shared's ActorNames (Identity binds it), never a users query here.
 */
final class ItemImagePayload
{
    /**
     * @return array{thumb: string, card: string, large: string, width: int, height: int}|null
     */
    public static function summary(?ItemImage $image): ?array
    {
        return $image === null ? null : ItemImageView::of($image->sha256, $image->mime, $image->width, $image->height)->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function detail(?ItemImage $image): ?array
    {
        if ($image === null) {
            return null;
        }
        $uploader = $image->uploaded_by_user_id;
        $name = $uploader === null ? null : (app(ActorNames::class)->names([$uploader])[$uploader] ?? null);

        return ['id' => $image->id]
            + ItemImageView::of($image->sha256, $image->mime, $image->width, $image->height)->toArray()
            + [
                'origin' => $image->origin,
                'uploaded_at' => $image->created_at?->toIso8601String(),
                'uploaded_by' => $uploader === null ? null : ['id' => $uploader, 'name' => $name],
            ];
    }
}

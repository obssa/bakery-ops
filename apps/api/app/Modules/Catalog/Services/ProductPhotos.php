<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Exceptions\CatalogError;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemImage;
use App\Modules\Catalog\Support\ProductPhoto;
use App\Modules\Catalog\Support\ProductPhotoPipeline;
use App\Shared\Actor\ActorContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Sets and removes the display photo of a product (step 6.1a, phase-6 plan 4.1, ADR 0022). The only writer of item_images and
 * items.image_id. Resolved per use (it reads the scoped ActorContext), never a singleton.
 *
 * set(): refuses an ingredient (catalog.image.not_a_product); a client_uuid already stored answers its row and writes nothing (rule 7).
 * Otherwise the photo is made (ProductPhotoPipeline) before any lock is taken, its three files are written under their sha256 unless they
 * are there already (two items may share a photo; files are content-addressed, so a write that is later rolled back leaves only a file a
 * retry reuses), and then, in one transaction under a row lock on the item: the new row (inserted under a savepoint; a concurrent upload
 * of the same client_uuid that won is read back with a locking read and answered instead, with nothing written), the previous row
 * soft-deleted (its files stay until the prune), items.image_id, the catalog_version bump (BumpsCatalogVersion on both models) and the
 * audit row catalog.item.image_set {item_id, before_image_id, after_image_id, crop, width, height, origin}.
 *
 * remove(): under the same lock, soft-deletes the current row, clears items.image_id, bumps catalog_version and audits
 * catalog.item.image_removed {item_id, before_image_id}. A product without a photo writes nothing (no audit row, no bump).
 */
final class ProductPhotos
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ActorContext $actor,
    ) {}

    /**
     * The returned row's wasRecentlyCreated says whether this call stored it.
     *
     * @param  array{x: int, y: int, width: int, height: int}|null  $crop  in source pixels after the EXIF orientation; null for a centre crop
     *
     * @throws CatalogError a refusal (nothing stored)
     */
    public function set(Item $item, string $bytes, ?array $crop, string $clientUuid, string $origin = 'upload'): ItemImage
    {
        if (! in_array($origin, ItemImage::ORIGINS, true)) {
            throw new InvalidArgumentException("Unknown photo origin [{$origin}].");
        }
        if (! $item->isProduct()) {
            throw CatalogError::imageNotAProduct();
        }
        $existing = ItemImage::query()->withTrashed()->where('client_uuid', $clientUuid)->first();
        if ($existing !== null) {
            return $existing;
        }

        $photo = ProductPhotoPipeline::fromConfig()->make($bytes, $crop);
        $diskName = (string) config('catalog.images.disk');
        $this->store($diskName, $photo);

        $row = DB::transaction(function () use ($item, $photo, $diskName, $clientUuid, $origin): ItemImage {
            $locked = Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $beforeId = $locked->image_id;

            $row = new ItemImage;
            $row->forceFill([
                'branch_id' => $locked->branch_id,
                'item_id' => $locked->id,
                'sha256' => $photo->sha256,
                'width' => $photo->width,
                'height' => $photo->height,
                'mime' => $photo->mime,
                'bytes' => $photo->bytes,
                'disk' => $diskName,
                'key_prefix' => $photo->keyPrefix(),
                'origin' => $origin,
                'uploaded_by_user_id' => $origin === 'upload' ? $this->actor->requireUserId() : null,
                'acting_user_id' => $this->actor->actingUserId(),
                'shift_instance_id' => $this->actor->shiftInstanceId(),
                'station_id' => $this->actor->stationId(),
                'device_id' => $this->actor->deviceId(),
                'client_uuid' => $clientUuid,
            ]);
            try {
                // a savepoint: a lost race undoes this insert only (PostgreSQL would abort the whole transaction)
                DB::transaction(fn () => $row->save());
            } catch (UniqueConstraintViolationException) {
                // a concurrent set of the same client_uuid won (ItemImageRaceTest): answer its row and write nothing. A locking read: a plain
                // one inside this transaction could read a snapshot taken before the winner committed (trap "Duplicate create inside a transaction")
                return ItemImage::query()->withTrashed()->where('client_uuid', $clientUuid)->sharedLock()->firstOrFail();
            }

            if ($beforeId !== null) {
                ItemImage::query()->whereKey($beforeId)->first()?->delete();
            }
            $locked->forceFill(['image_id' => $row->id])->save();

            $this->audit->recordEvent('catalog.item.image_set', $locked->getMorphClass(), $locked->id, [
                'item_id' => $locked->id,
                'before_image_id' => $beforeId,
                'after_image_id' => $row->id,
                'crop' => $photo->crop->toArray(),
                'width' => $photo->width,
                'height' => $photo->height,
                'origin' => $origin,
            ]);

            return $row;
        });

        $this->sync($item, $row->wasRecentlyCreated ? $row->id : null, $row->wasRecentlyCreated);

        return $row;
    }

    /**
     * @return bool whether a photo was removed (false: the product had none, nothing was written)
     */
    public function remove(Item $item): bool
    {
        $removed = DB::transaction(function () use ($item): bool {
            $locked = Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $beforeId = $locked->image_id;
            if ($beforeId === null) {
                return false;
            }

            ItemImage::query()->whereKey($beforeId)->first()?->delete();
            $locked->forceFill(['image_id' => null])->save();

            $this->audit->recordEvent('catalog.item.image_removed', $locked->getMorphClass(), $locked->id, [
                'item_id' => $locked->id,
                'before_image_id' => $beforeId,
            ]);

            return true;
        });

        $this->sync($item, null, $removed);

        return $removed;
    }

    /**
     * Writes the renditions that are not on the disk yet (the same sha256 is the same bytes). Each file is written under a temporary name
     * and moved into place, so a reader never sees half a file, even while a second upload of the same photo writes it again: the URL is
     * cached as immutable, so a half-written answer would be kept for good.
     */
    private function store(string $diskName, ProductPhoto $photo): void
    {
        $disk = Storage::disk($diskName);
        foreach ($photo->renditions as $rendition => $bytes) {
            $path = $photo->keyPrefix().'/'.$rendition.'.'.$photo->extension;
            if ($disk->exists($path)) {
                continue;
            }
            $temporary = $photo->keyPrefix().'/.'.$rendition.'.'.Str::random(12).'.tmp';
            $disk->put($temporary, $bytes);
            $disk->move($temporary, $path);
        }
    }

    /** The caller's model follows what was written, so it can be answered without a reload. */
    private function sync(Item $item, ?int $imageId, bool $changed): void
    {
        if (! $changed) {
            return;
        }
        $item->setAttribute('image_id', $imageId);
        $item->syncOriginalAttribute('image_id');
        $item->unsetRelation('image');
    }
}

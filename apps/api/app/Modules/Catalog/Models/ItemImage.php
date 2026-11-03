<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Support\BumpsCatalogVersion;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One display photo of a product (step 6.1a, phase-6 plan section 3 M1, ADR 0022), written only by Services\ProductPhotos. The
 * product's current photo is the row items.image_id names; a replace makes a new row and soft-deletes the old one (its files wait for
 * the prune of 6.1b), a remove soft-deletes it. Nothing else ever changes a row. The files are <key_prefix>/<rendition>.<ext> on the
 * disk named by `disk`; the public URL names the sha256 only (Contracts\ItemImageView), never the disk or the prefix. Every save and
 * every delete bumps the branch's catalog_version (the menu's ETag).
 *
 * @property int $id
 * @property int $branch_id
 * @property int $item_id
 * @property string $sha256
 * @property int $width
 * @property int $height
 * @property string $mime
 * @property int $bytes
 * @property string $disk
 * @property string $key_prefix
 * @property string $origin
 * @property int|null $uploaded_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $station_id
 * @property int|null $device_id
 * @property string $client_uuid
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Item $item
 */
final class ItemImage extends Model
{
    use BranchScoped;
    use BumpsCatalogVersion;
    use SoftDeletes;

    public const ORIGINS = ['upload', 'demo'];

    protected $table = 'item_images';

    /** @var list<string> nothing is mass-assigned: ProductPhotos fills every column with forceFill */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'item_id' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'uploaded_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'shift_instance_id' => 'integer',
            'station_id' => 'integer',
            'device_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /** `webp` or `jpg`: the extension of all three renditions, from the row's mime. */
    public function extension(): string
    {
        return self::extensionOf($this->mime);
    }

    public static function extensionOf(string $mime): string
    {
        return $mime === 'image/webp' ? 'webp' : 'jpg';
    }

    /** The file of one rendition on the row's disk, relative to the disk. */
    public function path(string $rendition): string
    {
        return $this->key_prefix.'/'.$rendition.'.'.$this->extension();
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id')->withTrashed();
    }
}

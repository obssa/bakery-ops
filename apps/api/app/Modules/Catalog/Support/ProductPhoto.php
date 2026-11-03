<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

/**
 * What ProductPhotoPipeline made of one upload, before anything is stored: the three renditions' bytes (thumb, card, large), their one
 * format, the large rendition's size, the sha256 of the large bytes (it names the files and the URL), the bytes of all three together
 * and the crop that was used, in source pixels.
 */
final readonly class ProductPhoto
{
    /**
     * @param  array{thumb: string, card: string, large: string}  $renditions
     */
    public function __construct(
        public array $renditions,
        public string $mime,
        public string $extension,
        public int $width,
        public int $height,
        public string $sha256,
        public int $bytes,
        public CropRect $crop,
    ) {}

    /** items/<sha[0..1]>/<sha>: where the files go on the media disk, relative to it. */
    public function keyPrefix(): string
    {
        return 'items/'.substr($this->sha256, 0, 2).'/'.$this->sha256;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Exceptions\CatalogError;

/**
 * The 4:3 rectangle of a product photo, in the pixels of the uploaded image after its EXIF orientation (step 6.1a, phase-6 plan 4.1
 * step 2). Exact 4:3 means 4k x 3k pixels. Integer arithmetic only.
 *
 * - centred(): the largest exact 4:3 rectangle, centred, when the admin sent none.
 * - given(): the rectangle the admin framed. It must lie inside the image and be within 1 px of 4:3 (|4h - 3w| <= 4, i.e. the height
 *   within 1 px of 3w/4 or the width within 1 px of 4h/3), else catalog.image.crop_invalid. It is then snapped to the largest exact
 *   4:3 rectangle inside it, centred in it, so the snap never leaves the image and loses at most 3 px a side.
 */
final readonly class CropRect
{
    private function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {}

    public static function centred(int $imageWidth, int $imageHeight): self
    {
        $k = max(0, min(intdiv($imageWidth, 4), intdiv($imageHeight, 3)));

        return new self(intdiv($imageWidth - 4 * $k, 2), intdiv($imageHeight - 3 * $k, 2), 4 * $k, 3 * $k);
    }

    /**
     * @throws CatalogError catalog.image.crop_invalid
     */
    public static function given(int $x, int $y, int $width, int $height, int $imageWidth, int $imageHeight): self
    {
        $inside = $x >= 0 && $y >= 0 && $width >= 4 && $height >= 3 && $x + $width <= $imageWidth && $y + $height <= $imageHeight;
        if (! $inside || abs(4 * $height - 3 * $width) > 4) {
            throw CatalogError::imageCropInvalid();
        }
        $k = min(intdiv($width, 4), intdiv($height, 3));

        return new self($x + intdiv($width - 4 * $k, 2), $y + intdiv($height - 3 * $k, 2), 4 * $k, 3 * $k);
    }

    public function isAtLeast(int $minWidth, int $minHeight): bool
    {
        return $this->width >= $minWidth && $this->height >= $minHeight;
    }

    /**
     * A size of this crop's shape no larger than the crop itself: $width x $height when the crop is at least that big, else the crop's
     * own size (a rendition is never upscaled).
     *
     * @return array{0: int, 1: int}
     */
    public function fit(int $width, int $height): array
    {
        return $this->width >= $width && $this->height >= $height ? [$width, $height] : [$this->width, $this->height];
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}
     */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'width' => $this->width, 'height' => $this->height];
    }
}

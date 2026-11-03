<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Exceptions\CatalogError;
use App\Shared\Files\FilesError;
use App\Shared\Files\ImageProcessor;
use App\Shared\Files\ProcessedImage;
use GdImage;

/**
 * How a product photo is made (step 6.1a, phase-6 plan 4.1, ADR 0022), on top of Shared\Files\ImageProcessor. Stores nothing:
 * Services\ProductPhotos writes the result.
 *
 * 1. ImageProcessor::load() checks and decodes the upload (type, pixel cap, byte cap, a whole file) and turns it by its EXIF orientation.
 * 2. The crop: the rectangle given (inside the image, within 1 px of 4:3, snapped to exact 4:3; CropRect) or the largest centred one.
 *    Under min_width x min_height source pixels it is refused (catalog.image.too_small).
 * 3. Three renditions, each resampled once from the crop onto white and never upscaled: thumb 320 x 240, card 800 x 600 and large
 *    1600 x 1200, or the crop's own size when it is smaller (with the 800 x 600 minimum, thumb and card always have their sizes).
 * 4. Encoded by ImageProcessor::encodeScrubbed: WebP at webp_quality when the format is `webp`, or `auto` and gd has WebP; else JPEG at
 *    jpeg_quality. No metadata, re-read to prove it.
 * 5. The sha256 of the large bytes names the files.
 *
 * Refusals are CatalogError (catalog.image.*). Plain values in, so the unit tests build it without the container; fromConfig() reads
 * config/catalog.php.
 */
final class ProductPhotoPipeline
{
    /** @var array<'thumb'|'card'|'large', array{0: int, 1: int}> the nominal size of each rendition, smallest first */
    public const RENDITIONS = ['thumb' => [320, 240], 'card' => [800, 600], 'large' => [1600, 1200]];

    public const FORMATS = ['auto', 'webp', 'jpeg'];

    public function __construct(
        private readonly string $format = 'auto',
        private readonly int $webpQuality = 82,
        private readonly int $jpegQuality = 85,
        private readonly int $minWidth = 800,
        private readonly int $minHeight = 600,
        private readonly int $maxBytes = 20971520,
        private readonly int $maxPixels = 24000000,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            format: (string) config('catalog.images.format', 'auto'),
            webpQuality: (int) config('catalog.images.webp_quality', 82),
            jpegQuality: (int) config('catalog.images.jpeg_quality', 85),
            minWidth: (int) config('catalog.images.min_width', 800),
            minHeight: (int) config('catalog.images.min_height', 600),
            maxBytes: (int) config('catalog.images.max_upload_bytes', 20971520),
            maxPixels: (int) config('catalog.images.max_pixels', 24000000),
        );
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}|null  $crop  in the pixels of the image after its EXIF orientation; null for a centre crop
     *
     * @throws CatalogError a refusal (catalog.image.*)
     */
    public function make(string $bytes, ?array $crop = null): ProductPhoto
    {
        $format = $this->format();
        $processor = new ImageProcessor(
            quality: $format === 'webp' ? $this->webpQuality : $this->jpegQuality,
            maxPixels: $this->maxPixels,
            maxBytes: $this->maxBytes,
        );

        try {
            $image = $processor->load($bytes);
            $imageWidth = imagesx($image);
            $imageHeight = imagesy($image);
            $rect = $crop === null
                ? CropRect::centred($imageWidth, $imageHeight)
                : CropRect::given($crop['x'], $crop['y'], $crop['width'], $crop['height'], $imageWidth, $imageHeight);
            if (! $rect->isAtLeast($this->minWidth, $this->minHeight)) {
                throw CatalogError::imageTooSmall($this->minWidth, $this->minHeight, $rect->width, $rect->height);
            }

            $thumb = $this->render($processor, $image, $rect, $format, self::RENDITIONS['thumb']);
            $card = $this->render($processor, $image, $rect, $format, self::RENDITIONS['card']);
            $large = $this->render($processor, $image, $rect, $format, self::RENDITIONS['large']);
            unset($image);
        } catch (FilesError $e) {
            throw CatalogError::fromFilesError($e);
        }
        $renditions = ['thumb' => $thumb->bytes, 'card' => $card->bytes, 'large' => $large->bytes];

        return new ProductPhoto(
            renditions: $renditions,
            mime: $large->mime,
            extension: $large->mime === 'image/webp' ? 'webp' : 'jpg',
            width: $large->width,
            height: $large->height,
            sha256: hash('sha256', $renditions['large']),
            bytes: strlen($renditions['thumb']) + strlen($renditions['card']) + strlen($renditions['large']),
            crop: $rect,
        );
    }

    /**
     * One rendition: the crop resampled once to its nominal size, or to the crop's own size when the crop is smaller, then encoded.
     *
     * @param  array{0: int, 1: int}  $nominal
     */
    private function render(ImageProcessor $processor, GdImage $image, CropRect $rect, string $format, array $nominal): ProcessedImage
    {
        [$width, $height] = $rect->fit($nominal[0], $nominal[1]);

        return $processor->encodeScrubbed($processor->resample($image, $rect->x, $rect->y, $rect->width, $rect->height, $width, $height), $format);
    }

    /**
     * `webp` or `jpeg`: what the configured format means on this host. `auto` is WebP where gd has it (the live host) and JPEG where it
     * has not; a forced `webp` on a gd without it is catalog.image.unavailable, never a silent JPEG; anything unknown reads as auto.
     */
    public function format(): string
    {
        return match ($this->format) {
            'jpeg' => 'jpeg',
            'webp' => ImageProcessor::canWebp() ? 'webp' : throw CatalogError::imageUnavailable(),
            default => ImageProcessor::canWebp() ? 'webp' : 'jpeg',
        };
    }
}

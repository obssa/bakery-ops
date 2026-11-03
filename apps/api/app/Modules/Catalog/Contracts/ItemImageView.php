<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

/**
 * A product's display photo as other modules show it (step 6.1a, contract orders->catalog; ItemImages::for): one URL per rendition and
 * the large rendition's size, which a srcSet reads (`thumb 320w, card 800w, large <width>w`). The URLs are absolute paths on the API's
 * own origin, /api/v1/media/items/<sha256>/<rendition>.<ext>, without a host: the live shell shares the origin, the CI web prefixes its
 * API URL. They change whenever the photo changes, so they may be cached for good. Nothing here says where the files live.
 */
final readonly class ItemImageView
{
    public const PATH = '/api/v1/media/items/';

    public function __construct(
        public string $thumb,
        public string $card,
        public string $large,
        public int $width,
        public int $height,
    ) {}

    /** The view of a photo from its sha256, its mime (image/webp or image/jpeg) and the large rendition's size. */
    public static function of(string $sha256, string $mime, int $width, int $height): self
    {
        $ext = $mime === 'image/webp' ? 'webp' : 'jpg';

        return new self(
            self::PATH.$sha256.'/thumb.'.$ext,
            self::PATH.$sha256.'/card.'.$ext,
            self::PATH.$sha256.'/large.'.$ext,
            $width,
            $height,
        );
    }

    /**
     * @return array{thumb: string, card: string, large: string, width: int, height: int}
     */
    public function toArray(): array
    {
        return ['thumb' => $this->thumb, 'card' => $this->card, 'large' => $this->large, 'width' => $this->width, 'height' => $this->height];
    }
}

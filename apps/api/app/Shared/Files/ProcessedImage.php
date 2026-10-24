<?php

declare(strict_types=1);

namespace App\Shared\Files;

/** The result of ImageProcessor: a clean JPEG and what was read back from it. */
final class ProcessedImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly int $width,
        public readonly int $height,
        public readonly string $mime = 'image/jpeg',
    ) {}
}

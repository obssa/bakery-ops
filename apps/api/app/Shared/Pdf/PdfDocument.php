<?php

declare(strict_types=1);

namespace App\Shared\Pdf;

/** A printed PDF (PdfRenderer): its bytes and how many pages it has, so a caller with a page limit can lay it out again. */
final readonly class PdfDocument
{
    public function __construct(
        public string $bytes,
        public int $pages,
    ) {}
}

<?php

/*
 * Catalog settings (step 6.1a, phase-6 plan 4.1, ADR 0022). Plain values only, so `config:cache` works on the shared host
 * (DeployableCachesTest).
 *
 * images: the display photo of a product (App\Modules\Catalog\Support\ProductPhotoPipeline).
 *   disk              the Laravel disk the renditions live on: `media`, a private local disk under storage/ (config/filesystems.php),
 *                     never under public/, because a deploy rsyncs public/;
 *   format            auto (WebP when this host's gd has it, else JPEG), webp or jpeg; the tests force each;
 *   webp_quality, jpeg_quality   what the encoder writes;
 *   min_width, min_height        the smallest crop accepted, in source pixels (catalog.image.too_small below it); the web calls 1200 x 900
 *                     and more "sharp", but only this minimum is enforced (spec 4.3);
 *   max_upload_bytes  the largest upload read (catalog.image.too_large above it); the web sends at most 2400 px on the long edge;
 *   max_pixels        the largest image decoded, read from its header before anything is decoded.
 */
return [
    'images' => [
        'disk' => env('CATALOG_IMAGES_DISK', 'media'),
        'format' => env('CATALOG_IMAGES_FORMAT', 'auto'),
        'webp_quality' => 82,
        'jpeg_quality' => 85,
        'min_width' => 800,
        'min_height' => 600,
        'max_upload_bytes' => 20971520,
        'max_pixels' => 24000000,
    ],
];

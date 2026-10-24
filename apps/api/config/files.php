<?php

/*
 * Receipt photos and other uploads (phase-4 plan section 6, step 4.2a; ADR 0015). Plain values only, so `config:cache` works on the
 * shared host (DeployableCachesTest). The code only ever talks to Storage::disk(config('files.disk')); FILES_DISK=files_s3 moves the
 * bytes to an S3-compatible bucket (config/filesystems.php) without touching it. max_edge and quality are what ImageProcessor writes;
 * orphan_hours is read by files:purge-orphans (step 4.2b).
 */
return [
    'disk' => env('FILES_DISK', 'files'),
    'max_bytes' => 8388608,
    'max_pixels' => 24000000,
    'max_edge' => 1600,
    'quality' => 82,
    'url_ttl_seconds' => 300,
    'orphan_hours' => 24,
    'allowed' => ['image/jpeg', 'image/png', 'image/webp'],
];

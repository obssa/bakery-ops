<?php

/*
 * Receipt photos and other uploads (phase-4 plan section 6, step 4.2a). PLACEHOLDER of step 4.1a: plain values only, so
 * `config:cache` keeps working on the shared host (DeployableCachesTest). Step 4.2a adds the `files` disk to filesystems.php and the code
 * that reads these. The code only ever talks to Storage::disk(config('files.disk')); FILES_DISK=files_s3 moves the bytes to an
 * S3-compatible bucket without touching it (ADR 0015).
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

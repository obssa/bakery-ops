<?php

/*
 * Receipt printing through the print bridge (step 5.3a, phase-5 plan sections 4.3 and 6; ADR 0019). Plain values: config:cache keeps them,
 * and only PRINT_RASTER comes from the environment.
 *
 *   raster               draw the receipt as a picture (Amharic as pixels). Off, or a host without FreeType (print:doctor), prints the
 *                        printer's own Latin text with no Amharic instead.
 *   bridge_seen_seconds  POST /orders/{order}/print answers 409 payments.print.no_bridge when no bridge of the branch was seen this long
 *                        (a bridge polls every 3 to 15 s; its last_seen_at moves at most once a minute)
 *   claim_ack_seconds    a claimed job not reported printed or failed this long goes back to the queue (print:sweep, every minute)
 *   max_claims           a job is claimed at most this often; the last failure or silence makes it failed (PrintJobFailed)
 *   expire_minutes       a job older than this is expired, never printed: a receipt half an hour late is worse than none
 *   fonts                the Latin fonts of the picture, relative to the base path (Amharic is Noto Sans Ethiopic, resources/fonts)
 */
return [
    'raster' => (bool) env('PRINT_RASTER', true),
    'bridge_seen_seconds' => 120,
    'claim_ack_seconds' => 60,
    'max_claims' => 3,
    'expire_minutes' => 15,
    'fonts' => [
        'latin' => 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf',
        'latin_bold' => 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf',
    ],
];

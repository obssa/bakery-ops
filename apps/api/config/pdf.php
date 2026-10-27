<?php

/*
 * Every PDF the system prints (Shared\Pdf; the receipts of step 4.3e, the analytics exports and the weekly summary of step 4.4d).
 *   fonts_dir  dompdf's font folder, where the bundled Noto Sans Ethiopic is registered on first use (null: storage/fonts, which the deploy's
 *              rsync keeps; `php artisan receipts:fonts` warms it after a deploy). Was payments.receipts.fonts_dir until step 4.4d.
 * Plain values only: no closures, so `config:cache` keeps working on the shared host (DeployableCachesTest).
 */
return [
    'fonts_dir' => null,
];

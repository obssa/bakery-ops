<?php

declare(strict_types=1);

use App\Shared\Pdf\PdfFonts;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * `php artisan receipts:fonts` (step 4.3e; release.sh runs it after every deploy, never fatal): registers the bundled Noto Sans Ethiopic
 * into dompdf's font folder before the first receipt needs it. Since step 4.4d the font is Shared's (Shared\Pdf\PdfFonts,
 * tests/Modules/Shared/PdfFontsTest.php), because the analytics exports and the weekly summary print Amharic too; the command keeps its
 * name, so the deploy script does not change.
 */

pest()->group('module:payments', 'feature');

beforeEach(function () {
    $this->dir = storage_path('framework/testing/fonts-'.Str::random(8));
    config(['pdf.fonts_dir' => $this->dir]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('is warmed by receipts:fonts', function () {
    expect(Artisan::call('receipts:fonts'))->toBe(0)
        ->and(Artisan::output())->toContain('installed')->toContain($this->dir);
    expect(app(PdfFonts::class)->installed())->toBeTrue();

    expect(Artisan::call('receipts:fonts'))->toBe(0)
        ->and(Artisan::output())->toContain('already installed');
});

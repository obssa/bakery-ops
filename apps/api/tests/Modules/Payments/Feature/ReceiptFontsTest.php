<?php

declare(strict_types=1);

use App\Modules\Payments\Support\Receipts\ReceiptFonts;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * The Amharic font of the receipts (phase-4 plan 10.8 R-P, step 4.3e): Noto Sans Ethiopic Regular (SIL Open Font License 1.1, the licence
 * file next to it) is bundled in resources/fonts/noto-sans-ethiopic and registered into dompdf's font folder (storage/fonts on the host,
 * payments.receipts.fonts_dir in a test) the first time a receipt needs it: the TTF, its metrics (.ufm) and its entry in dompdf's
 * installed-fonts.json, for the normal and the bold weight (there is one face). Under the cache lock receipts:fonts, so two first receipts
 * at once do not write the folder together; files are written to a temporary name and moved into place. `php artisan receipts:fonts`
 * warms it after a deploy.
 */

pest()->group('module:payments', 'feature');

beforeEach(function () {
    $this->dir = storage_path('framework/testing/fonts-'.Str::random(8));
    config(['payments.receipts.fonts_dir' => $this->dir]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('bundles the font with its licence', function () {
    $fonts = app(ReceiptFonts::class);

    expect(is_file($fonts->source()))->toBeTrue()
        ->and(hash_file('sha256', $fonts->source()))->toBe(ReceiptFonts::SOURCE_SHA256)
        ->and(file_get_contents(dirname($fonts->source()).'/OFL.txt'))->toContain('SIL OPEN FONT LICENSE Version 1.1');
});

it('registers the font once, into an empty folder, for the normal and the bold weight', function () {
    $fonts = app(ReceiptFonts::class);
    expect($fonts->installed())->toBeFalse();

    expect($fonts->ensure())->toBeTrue();

    $families = json_decode((string) file_get_contents($this->dir.'/installed-fonts.json'), true);
    $entry = $families[ReceiptFonts::FAMILY] ?? null;
    expect($entry)->toBeArray()
        ->and($entry['normal'])->toBe($entry['bold'])
        ->and(is_file($this->dir.'/'.$entry['normal'].'.ttf'))->toBeTrue()
        ->and(is_file($this->dir.'/'.$entry['normal'].'.ufm'))->toBeTrue()
        ->and(hash_file('sha256', $this->dir.'/'.$entry['normal'].'.ttf'))->toBe(ReceiptFonts::SOURCE_SHA256)
        ->and($fonts->installed())->toBeTrue();
    $mtime = filemtime($this->dir.'/installed-fonts.json');

    expect($fonts->ensure())->toBeFalse()
        ->and(filemtime($this->dir.'/installed-fonts.json'))->toBe($mtime);
});

it('keeps the other fonts of the folder', function () {
    File::ensureDirectoryExists($this->dir);
    file_put_contents($this->dir.'/installed-fonts.json', json_encode(['some other font' => ['normal' => 'some_other_font_normal']]));

    app(ReceiptFonts::class)->ensure();

    $families = json_decode((string) file_get_contents($this->dir.'/installed-fonts.json'), true);
    expect($families)->toHaveKeys(['some other font', ReceiptFonts::FAMILY]);
});

it('registers it again when a file of it went missing', function () {
    $fonts = app(ReceiptFonts::class);
    $fonts->ensure();
    $entry = json_decode((string) file_get_contents($this->dir.'/installed-fonts.json'), true)[ReceiptFonts::FAMILY];
    unlink($this->dir.'/'.$entry['normal'].'.ufm');

    expect($fonts->installed())->toBeFalse()->and($fonts->ensure())->toBeTrue()->and($fonts->installed())->toBeTrue();
});

it('waits for the lock another request holds, and gives up without writing when it is not released', function () {
    $lock = Cache::lock(ReceiptFonts::LOCK, 30);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(ReceiptFonts::class)->ensure(0))->toThrow(LockTimeoutException::class);
        expect(is_file($this->dir.'/installed-fonts.json'))->toBeFalse();
    } finally {
        $lock->release();
    }

    expect(app(ReceiptFonts::class)->ensure(0))->toBeTrue();
});

it('is warmed by receipts:fonts', function () {
    expect(Artisan::call('receipts:fonts'))->toBe(0)
        ->and(Artisan::output())->toContain('installed')->toContain($this->dir);
    expect(app(ReceiptFonts::class)->installed())->toBeTrue();

    expect(Artisan::call('receipts:fonts'))->toBe(0)
        ->and(Artisan::output())->toContain('already installed');
});

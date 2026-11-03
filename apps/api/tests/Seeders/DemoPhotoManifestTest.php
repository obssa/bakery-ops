<?php

declare(strict_types=1);

use App\Console\Support\DemoProductImages;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\Demo\DemoPhaseTwo;

pest()->group('feature', 'seeds');

/*
 * The demo product photos (step 6.1c, phase-6 plan 6.1c and 10.2 Q4, ADR 0022): apps/api/resources/demo/product-images holds one photo per
 * demo product and manifest.json, an array of {slug, file, matches, source, licence} (the products' name_en, the source page, the licence;
 * no person's name: the repository is public). Every file is listed and present, 4:3 and at least 800 x 600, with no metadata; the names
 * are exactly the products the Phase 0 to 4 demo seeders make, which is what CI's demo branch and the live Demo Bakery hold.
 */

/** @return list<array<string, mixed>> the manifest as committed */
function demoPhotoManifest(): array
{
    $json = file_get_contents(resource_path('demo/product-images/manifest.json'));
    expect($json)->toBeString('manifest.json is missing');

    return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
}

/** @return list<string> the English names of every product the demo seeders make (Phase 0's catalog and Phase 2's gate product) */
function demoProductNames(): array
{
    return [...array_column(DemoBakery::PRODUCTS, 'en'), DemoPhaseTwo::GATE_PRODUCT['en']];
}

it('lists every file of the folder once, and every listed file is there (the folder holds only the photos, manifest.json and README.md)', function () {
    $folder = resource_path('demo/product-images');
    $files = array_column(demoPhotoManifest(), 'file');
    $onDisk = array_map('basename', glob($folder.'/*') ?: []);

    expect($files)->toHaveCount(29)
        ->and(array_unique($files))->toHaveCount(29)
        ->and($onDisk)->toEqualCanonicalizing([...$files, 'manifest.json', 'README.md']);
    foreach ($files as $file) {
        expect(is_file($folder.'/'.$file))->toBeTrue("{$file} is listed and missing");
    }
});

it('has exactly the five keys in every entry, a slug that names its file, a source page and a licence, and no person named', function () {
    foreach (demoPhotoManifest() as $i => $entry) {
        expect(array_keys($entry))->toBe(['slug', 'file', 'matches', 'source', 'licence'], "entry {$i}")
            ->and($entry['slug'])->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/')
            ->and($entry['file'])->toBe($entry['slug'].'.webp')
            ->and($entry['matches'])->toBeArray()->not->toBeEmpty()
            ->and($entry['source'])->toMatch('#^https://(unsplash\.com|www\.pexels\.com)/photos?/[^\s]+$#', "entry {$entry['slug']}: the source page")
            ->and($entry['licence'])->toBeIn(['Unsplash License', 'Pexels License']);
        foreach ($entry['matches'] as $name) {
            expect($name)->toBeString()->not->toBe('');
        }
    }
    expect(array_unique(array_column(demoPhotoManifest(), 'slug')))->toHaveCount(29)
        ->and(DemoProductImages::KEYS)->toBe(['slug', 'file', 'matches', 'source', 'licence']);
});

it('keeps every photo 1600 x 1200 (4:3, at least 800 x 600), a WebP without EXIF, XMP or ICC, under 350 KB each and 10 MB in all', function () {
    $folder = resource_path('demo/product-images');
    $total = 0;
    foreach (demoPhotoManifest() as $entry) {
        $bytes = (string) file_get_contents($folder.'/'.$entry['file']);
        $info = getimagesizefromstring($bytes);
        $total += strlen($bytes);

        expect($info)->toBeArray("{$entry['file']} is not an image")
            ->and([$info[0], $info[1], $info['mime']])->toBe([1600, 1200, 'image/webp'], $entry['file'])
            ->and($info[0] * 3)->toBe($info[1] * 4)
            ->and(strlen($bytes))->toBeLessThanOrEqual(350 * 1024, $entry['file'])
            ->and(array_intersect(photoWebpChunks($bytes), ['EXIF', 'XMP ', 'ICCP']))->toBe([], "{$entry['file']} carries metadata");
    }
    expect($total)->toBeLessThanOrEqual(10 * 1024 * 1024);
});

it('matches exactly the products the demo seeders make: every product has one entry, every name an entry lists is a product', function () {
    $matched = [];
    foreach (demoPhotoManifest() as $entry) {
        foreach ($entry['matches'] as $name) {
            $matched[] = $name;
        }
    }

    expect($matched)->toHaveCount(count(array_unique($matched)))
        ->and($matched)->toEqualCanonicalizing(demoProductNames())
        ->and(demoProductNames())->toHaveCount(29);
});

it('passes the check bakery:demo-product-images makes before it writes anything', function () {
    $manifest = app(DemoProductImages::class)->manifest();

    expect($manifest)->toHaveCount(29)
        ->and(array_column($manifest, 'slug'))->toBe(array_column(demoPhotoManifest(), 'slug'))
        ->and($manifest[0]['path'])->toBe(resource_path('demo/product-images/'.$manifest[0]['file']));
});

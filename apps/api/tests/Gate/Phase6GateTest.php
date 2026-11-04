<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemImage;
use App\Modules\Identity\Models\User;
use App\Shared\Actor\ActorContext;
use App\Shared\Files\ImageProcessor;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Tenancy\CurrentBranch;
use Database\Seeders\DemoSeeder;
use Database\Seeders\Phase0Seeder;
use Database\Seeders\Phase6Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * The Phase 6 gate's own checks (phase-6 plan section 6.5, 7 and 10.3; the manifest's gate criteria; ADR 0022). The gate-p6 job runs
 * this group on the compose stack, in the API image whose gd has WebP as the live host's has, before gate_check.
 *
 * - The renditions: a 2400 x 1800 upload through POST /items/{item}/image gives thumb 320 x 240, card 800 x 600 and large 1600 x 1200,
 *   in the format catalog.images.format `auto` picks on this host (WebP where gd has it, else JPEG); a 1000 x 750 crop keeps
 *   1000 x 750 as large, never upscaled, and draws exactly that rectangle.
 * - The minimum: a crop or a photo under 800 x 600 is refused with catalog.image.too_small in both languages, and nothing is stored;
 *   800 x 600 passes.
 * - The media route, signed out: each rendition with its type, Cache-Control public, max-age=31536000, immutable, its ETag and
 *   nosniff, and no cookie; 304 to a matching If-None-Match, strong or weak; 404 for an unknown sha, the other extension, a photo
 *   replaced and a photo removed.
 * - The demo bakery seeded as e2e-p6 seeds it (every phase, Phase6Seeder last): every product of the demo branch has a live demo
 *   photo, its three files are on the media disk and the route serves its card.
 * - Nothing under public/: the media disk is the local disk under storage/ (a deploy rsyncs public/ and would delete the photos), and
 *   an upload, a replace and a remove on that real disk, and the whole demo seed, leave public/ exactly as they found it.
 *
 * Each case also carries a module group (module:catalog, or seeds for the demo bakery), so mod-catalog and mod-shifts see it long before
 * the gate does. Every picture is built in memory with GD (tests/Support/images.php), except the demo photos the seed attaches.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    // the format the live host runs; an environment variable never decides it here
    config(['catalog.images.format' => 'auto']);
});

afterEach(function () {
    putenv('SEED_PHASE');
    app(CurrentBranch::class)->clear();
    app(ActorContext::class)->reset();
});

/** @return array{0: string, 1: string} the mime and extension `auto` writes on this host */
function phase6Format(): array
{
    return ImageProcessor::canWebp() ? ['image/webp', 'webp'] : ['image/jpeg', 'jpg'];
}

/** @return array{0: Branch, 1: User, 2: Item} a branch, its admin signed in (catalog.manage) and a product without a photo */
function phase6Shop(): array
{
    seedRoles();
    $branch = actInBranch(Branch::factory()->create());
    $admin = loginAs(makeUser($branch, 'admin'));
    withActor($admin->id);
    $cake = Item::factory()->product(Fulfilment::ToStock, 'slice')->create([
        'branch_id' => $branch->id, 'name_en' => 'Black forest cake', 'created_by_user_id' => $admin->id,
    ]);

    return [$branch, $admin, $cake];
}

/** @return array{0: int, 1: int, 2: string} width, height and mime of the file behind a media URL */
function phase6Size(string $url): array
{
    $bytes = photoFile($url);
    expect($bytes)->not->toBeNull("no file behind {$url}");
    $info = getimagesizefromstring((string) $bytes);
    expect($info)->toBeArray("the file behind {$url} is not a picture");

    return [$info[0], $info[1], $info['mime']];
}

/**
 * Every entry under public/, its path relative to public/ => size and modification time (a directory: "dir"). Symbolic links are
 * listed, never followed.
 *
 * @return array<string, string>
 */
function phase6PublicFiles(): array
{
    clearstatcache();
    $root = public_path();
    $entries = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($walk as $path => $entry) {
        $relative = substr((string) $path, strlen($root) + 1);
        $entries[$relative] = $entry->isLink() ? 'link' : ($entry->isDir() ? 'dir' : $entry->getSize().':'.$entry->getMTime());
    }
    ksort($entries);

    return $entries;
}

/** GET a media URL with no sign-in, no token and no header the earlier requests set. */
function phase6Media(mixed $test, string $url, array $headers = []): TestResponse
{
    app('auth')->forgetGuards();
    $response = $test->flushHeaders()->withHeaders($headers)->get($url);
    $test->flushHeaders();

    return $response;
}

/** @return list<string> the directives of a Cache-Control header, sorted */
function phase6Directives(?string $cacheControl): array
{
    $directives = array_map('trim', explode(',', (string) $cacheControl));
    sort($directives);

    return $directives;
}

it('makes thumb 320 x 240, card 800 x 600 and large 1600 x 1200 from a 2400 x 1800 upload, in the format auto picks on this host', function () {
    Storage::fake(config('catalog.images.disk'));
    [, , $cake] = phase6Shop();
    [$mime, $ext] = phase6Format();

    $image = photoPost($this, $cake->id, imgJpeg(2400, 1800))->assertCreated()->json('data.image');
    $row = ItemImage::query()->sole();

    expect([$image['width'], $image['height']])->toBe([1600, 1200])
        ->and([$row->width, $row->height, $row->mime])->toBe([1600, 1200, $mime])
        ->and($cake->fresh()?->image_id)->toBe($row->id)
        ->and($row->sha256)->toBe(hash('sha256', (string) photoFile($image['large'])));
    foreach (['thumb' => [320, 240], 'card' => [800, 600], 'large' => [1600, 1200]] as $rendition => [$width, $height]) {
        expect($image[$rendition])->toBe("/api/v1/media/items/{$row->sha256}/{$rendition}.{$ext}")
            ->and(phase6Size($image[$rendition]))->toBe([$width, $height, $mime], $rendition);
    }
})->group('gate:p6', 'feature', 'module:catalog');

it('keeps a 1000 x 750 crop at 1000 x 750 as large, never upscaled, with thumb and card at their sizes, and draws exactly that rectangle', function () {
    Storage::fake(config('catalog.images.disk'));
    [, , $cake] = phase6Shop();
    [$mime] = phase6Format();

    // 2400 x 1800, blue with a red 600 x 600 square in its top-left corner: the crop (0, 0, 1000, 750) is red only in its own top-left
    $image = photoPost($this, $cake->id, imgJpeg(2400, 1800), photoCrop(0, 0, 1000, 750))->assertCreated()->json('data.image');
    $audit = AuditLog::query()->where('action', 'catalog.item.image_set')->sole();

    expect([$image['width'], $image['height']])->toBe([1000, 750])
        ->and(phase6Size($image['large']))->toBe([1000, 750, $mime])
        ->and(phase6Size($image['card']))->toBe([800, 600, $mime])
        ->and(phase6Size($image['thumb']))->toBe([320, 240, $mime])
        ->and($audit->after['crop'] ?? null)->toBe(['x' => 0, 'y' => 0, 'width' => 1000, 'height' => 750])
        ->and(imgIsRed(imgPixel((string) photoFile($image['large']), 300, 300)))->toBeTrue('the crop starts in the red corner')
        ->and(imgIsRed(imgPixel((string) photoFile($image['large']), 800, 650)))->toBeFalse('and ends in the blue, not on a scaled whole');
})->group('gate:p6', 'feature', 'module:catalog');

it('refuses a crop or a photo under 800 x 600 with catalog.image.too_small in both languages and stores nothing; 800 x 600 passes', function () {
    Storage::fake(config('catalog.images.disk'));
    [, , $cake] = phase6Shop();

    $crop = photoPost($this, $cake->id, imgJpeg(2400, 1800), photoCrop(0, 0, 799, 600));
    $crop->assertStatus(422)
        ->assertJsonPath('message_key', 'catalog.image.too_small')
        ->assertJsonPath('min_width', 800)
        ->assertJsonPath('min_height', 600);
    expect($crop->json('message_en'))->toBe(trans('catalog.image.too_small', [], 'en'))->not->toBe('catalog.image.too_small')
        ->and($crop->json('message_am'))->toBe(trans('catalog.image.too_small', [], 'am'))->not->toBe($crop->json('message_en'));

    photoPost($this, $cake->id, imgJpeg(640, 480))->assertStatus(422)
        ->assertJsonPath('message_key', 'catalog.image.too_small')
        ->assertJsonPath('width', 640)
        ->assertJsonPath('height', 480);

    expect(ItemImage::query()->withTrashed()->count())->toBe(0)
        ->and(Storage::disk(config('catalog.images.disk'))->allFiles())->toBe([])
        ->and($cake->fresh()?->image_id)->toBeNull();

    photoPost($this, $cake->id, imgJpeg(2400, 1800), photoCrop(0, 0, 800, 600))->assertCreated()
        ->assertJsonPath('data.image.width', 800)
        ->assertJsonPath('data.image.height', 600);
})->group('gate:p6', 'feature', 'module:catalog');

it('serves each rendition signed out with its type, immutable for a year, an ETag and nosniff; 304 on If-None-Match; 404 for an unknown, replaced or removed photo', function () {
    Storage::fake(config('catalog.images.disk'));
    [, $admin, $cake] = phase6Shop();
    [$mime, $ext] = phase6Format();
    $other = $ext === 'webp' ? 'jpg' : 'webp';

    $first = photoPost($this, $cake->id, imgJpeg(2400, 1800))->assertCreated()->json('data.image');
    $sha = ItemImage::query()->sole()->sha256;

    foreach (['thumb', 'card', 'large'] as $rendition) {
        $response = phase6Media($this, $first[$rendition]);
        $response->assertOk();
        expect($response->streamedContent())->toBe(photoFile($first[$rendition]))
            ->and($response->headers->get('Content-Type'))->toBe($mime)
            ->and(phase6Directives($response->headers->get('Cache-Control')))->toBe(['immutable', 'max-age=31536000', 'public'])
            ->and($response->headers->get('ETag'))->toBe("\"{$sha}-{$rendition}\"")
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->getCookies())->toBe([]);

        foreach (["\"{$sha}-{$rendition}\"", "W/\"{$sha}-{$rendition}\""] as $ifNoneMatch) {
            $again = phase6Media($this, $first[$rendition], ['If-None-Match' => $ifNoneMatch]);
            $again->assertStatus(304);
            expect($again->getContent())->toBe('')
                ->and($again->headers->get('ETag'))->toBe("\"{$sha}-{$rendition}\"")
                ->and(phase6Directives($again->headers->get('Cache-Control')))->toBe(['immutable', 'max-age=31536000', 'public']);
        }
    }
    phase6Media($this, $first['card'], ['If-None-Match' => "\"{$sha}-thumb\""])->assertOk();

    phase6Media($this, '/api/v1/media/items/'.str_repeat('0', 64)."/card.{$ext}")->assertNotFound();
    phase6Media($this, "/api/v1/media/items/{$sha}/card.{$other}")->assertNotFound();

    // replaced: the old URLs answer 404 at once, the new ones 200
    loginAs($admin);
    $second = photoPost($this, $cake->id, imgJpeg(1600, 1200))->assertCreated()->json('data.image');
    expect($second['card'])->not->toBe($first['card']);
    foreach (['thumb', 'card', 'large'] as $rendition) {
        phase6Media($this, $first[$rendition])->assertNotFound();
        phase6Media($this, $second[$rendition])->assertOk();
    }

    // removed: the URLs answer 404, and the product has no photo
    loginAs($admin);
    $this->flushHeaders()->withHeaders(idempotencyKey())->deleteJson("/api/v1/items/{$cake->id}/image")
        ->assertOk()->assertJsonPath('data.image', null);
    foreach (['thumb', 'card', 'large'] as $rendition) {
        phase6Media($this, $second[$rendition])->assertNotFound();
    }
    expect($cake->fresh()?->image_id)->toBeNull()
        ->and(ItemImage::query()->count())->toBe(0)
        ->and(ItemImage::query()->onlyTrashed()->count())->toBe(2);
})->group('gate:p6', 'feature', 'module:catalog');

it('gives every product of the seeded demo bakery a live demo photo after Phase6Seeder, on the media disk and served by the route', function () {
    Storage::fake(config('catalog.images.disk'));
    Storage::fake((string) config('files.disk')); // Phase 4's history keeps receipt photos
    [$mime, $ext] = phase6Format();
    $public = phase6PublicFiles();

    putenv('SEED_PHASE'); // every phase, Phase6Seeder last, as e2e-p6 seeds the stack (SEED_PHASE=6)
    expect(DemoSeeder::maxPhase())->toBe(6)
        ->and(DemoSeeder::seedersUpTo(DemoSeeder::maxPhase()))->toContain(Phase6Seeder::class);
    $this->seed(DemoSeeder::class);
    app(CurrentBranch::class)->clear();
    app(ActorContext::class)->reset();

    $branch = Phase0Seeder::existingBranch();
    expect($branch)->not->toBeNull('the seed made no demo branch');
    $products = Item::query()->withoutGlobalScope(BranchScope::class)
        ->where('branch_id', $branch?->id)->where('kind', ItemKind::Product)->orderBy('id')->get();
    $images = ItemImage::query()->withoutGlobalScope(BranchScope::class)
        ->whereIn('id', $products->pluck('image_id')->filter()->all())->get()->keyBy('id');
    $disk = Storage::disk(config('catalog.images.disk'));

    $problems = [];
    foreach ($products as $product) {
        $image = $product->image_id === null ? null : $images->get($product->image_id);
        if (! $image instanceof ItemImage) {
            $problems[] = "{$product->name_en}: no live photo";

            continue;
        }
        if ($image->origin !== 'demo' || $image->branch_id !== $branch?->id || $image->mime !== $mime || [$image->width, $image->height] !== [1600, 1200]) {
            $problems[] = "{$product->name_en}: origin {$image->origin}, branch {$image->branch_id}, {$image->mime}, {$image->width} x {$image->height}";
        }
        foreach (['thumb', 'card', 'large'] as $rendition) {
            if (! $disk->exists($image->path($rendition))) {
                $problems[] = "{$product->name_en}: no {$rendition} file";
            }
        }
        $card = phase6Media($this, "/api/v1/media/items/{$image->sha256}/card.{$ext}");
        if ($card->getStatusCode() !== 200 || $card->headers->get('Content-Type') !== $mime) {
            $problems[] = "{$product->name_en}: the route answered {$card->getStatusCode()} {$card->headers->get('Content-Type')}";
        }
    }

    // the 28 products of DemoBakery::PRODUCTS and Phase 2's gate product, as the demo photos' manifest covers them
    expect($products->count())->toBeGreaterThanOrEqual(29)
        ->and($problems)->toBe([])
        ->and(phase6PublicFiles())->toBe($public, 'the seed wrote under public/');
})->group('gate:p6', 'feature', 'seeds');

it('keeps product photos on the media disk under storage/, never under public/: an upload, a replace and a remove leave public/ as they found it', function () {
    $name = (string) config('catalog.images.disk');
    $config = (array) config("filesystems.disks.{$name}");
    $root = rtrim((string) ($config['root'] ?? ''), '/\\');

    expect($name)->toBe('media')
        ->and($config['driver'] ?? null)->toBe('local')
        ->and(str_starts_with($root, storage_path().DIRECTORY_SEPARATOR))->toBeTrue("the media disk's root {$root} is not under storage/")
        ->and(str_starts_with($root, public_path()))->toBeFalse("the media disk's root {$root} is under public/")
        ->and(rtrim((string) config('filesystems.disks.public.root'), '/\\'))->not->toBe($root);

    // the real media disk, not a fake: the files land where the live host keeps them, and are removed again below
    $public = phase6PublicFiles();
    [, , $cake] = phase6Shop();
    $written = [];
    try {
        $first = photoPost($this, $cake->id, imgJpeg(1200, 900))->assertCreated()->json('data.image');
        $firstRow = ItemImage::query()->sole();
        $written[] = $firstRow->key_prefix;
        $second = photoPost($this, $cake->id, imgJpeg(1600, 1200))->assertCreated()->json('data.image');
        $secondRow = ItemImage::query()->sole();
        $written[] = $secondRow->key_prefix;

        foreach ([$firstRow, $secondRow] as $row) {
            expect($row->disk)->toBe('media');
            foreach (['thumb', 'card', 'large'] as $rendition) {
                expect(is_file($root.'/'.$row->path($rendition)))->toBeTrue("{$row->path($rendition)} is not under the media disk's root");
            }
        }
        $this->flushHeaders()->withHeaders(idempotencyKey())->deleteJson("/api/v1/items/{$cake->id}/image")->assertOk();
        phase6Media($this, $second['card'])->assertNotFound();
        phase6Media($this, $first['card'])->assertNotFound();

        $after = phase6PublicFiles();
        $named = array_filter(array_keys($after), fn (string $path): bool => str_contains($path, $firstRow->sha256) || str_contains($path, $secondRow->sha256));
        expect($after)->toBe($public, 'a photo was written under public/')
            ->and($named)->toBe([]);
    } finally {
        $disk = Storage::disk($name);
        foreach ($written as $prefix) {
            $disk->deleteDirectory($prefix);
            // items/<ab> goes too when nothing else is in it
            if ($disk->allFiles(dirname($prefix)) === [] && $disk->allDirectories(dirname($prefix)) === []) {
                $disk->deleteDirectory(dirname($prefix));
            }
        }
    }
})->group('gate:p6', 'feature', 'module:catalog');

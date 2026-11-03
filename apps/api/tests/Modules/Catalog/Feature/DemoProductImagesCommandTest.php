<?php

declare(strict_types=1);

use App\Console\Support\DemoProductImages;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemImage;
use App\Modules\Catalog\Services\ProductPhotos;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

pest()->group('module:catalog', 'feature');

/*
 * `bakery:demo-product-images --branch=<id> {--as=} {--dry-run} {--force}` (step 6.1c, phase-6 plan 4.4, ADR 0022): attaches a demo
 * photo (origin `demo`) to each product of a demo branch that has none, matched by name_en from resources/demo/product-images/manifest.json,
 * through Catalog's ProductPhotos (the pipeline's centre crop, the three renditions, the sha256, catalog_version, the audit row), as the
 * branch's first active admin or --as. An uploaded photo is never touched; --force replaces only photos with origin `demo`. It prints
 * per product what it did or, with --dry-run, would do, and the matched, attached, skipped and unmatched counts; --dry-run writes nothing,
 * not even a file. A manifest entry without a source or a licence, a missing file, or a photo that is not 4:3 or under 800 x 600 refuses
 * the whole run before anything is written. Most cases run on a manifest of JPEG fixtures built here with GD; one on the committed photos.
 */

beforeEach(function () {
    Storage::fake(config('catalog.images.disk'));
    config(['catalog.images.format' => 'jpeg']);
    seedRoles();
    $this->branch = Branch::factory()->create(['name' => 'Demo Bakery', 'settings' => ['demo' => true]]);
    $this->admin = makeUser($this->branch, 'admin', ['name' => 'First admin']);
    $this->product = fn (string $name, ?Branch $in = null): Item => Item::factory()->product(Fulfilment::ToStock)
        ->create(['branch_id' => ($in ?? $this->branch)->id, 'name_en' => $name, 'created_by_user_id' => $this->admin->id]);
    $this->disk = Storage::disk(config('catalog.images.disk'));
    $this->fixtures = [];
});

afterEach(function () {
    foreach ($this->fixtures as $folder) {
        foreach (glob($folder.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($folder);
    }
    app(CurrentBranch::class)->clear();
});

/**
 * A manifest folder of its own, and the command's service bound to it: each entry [slug, [names], width, height] gets a JPEG of that
 * size (its colour from the slug, so two folders with the same slug and another $tint give two different photos); $edit may change the
 * entries before they are written.
 *
 * @param  list<array{0: string, 1: list<string>, 2?: int, 3?: int}>  $entries
 * @param  (callable(list<array<string, mixed>>): mixed)|null  $edit
 */
function demoPhotoFolder(object $test, array $entries, ?callable $edit = null, int $tint = 0): string
{
    $folder = sys_get_temp_dir().'/demo-photos-'.Str::random(10);
    mkdir($folder);
    $test->fixtures[] = $folder;
    $manifest = [];
    foreach ($entries as $entry) {
        [$slug, $names] = $entry;
        $width = $entry[2] ?? 1200;
        $height = $entry[3] ?? 900;
        $image = imagecreatetruecolor($width, $height);
        $seed = crc32($slug) + $tint;
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, $seed % 200, intdiv($seed, 7) % 200, intdiv($seed, 13) % 200));
        imagejpeg($image, "{$folder}/{$slug}.jpg", 90);
        $manifest[] = ['slug' => $slug, 'file' => "{$slug}.jpg", 'matches' => $names, 'source' => "https://example.org/photos/{$slug}", 'licence' => 'Test Licence'];
    }
    if ($edit !== null) {
        $edited = $edit($manifest);
        $manifest = is_array($edited) ? $edited : $manifest;
    }
    file_put_contents("{$folder}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    app()->bind(DemoProductImages::class, fn () => new DemoProductImages($folder));

    return $folder;
}

/** @return array{0: int, 1: string} the exit code and the output of one run */
function demoPhotosRun(array $options): array
{
    $exit = Artisan::call('bakery:demo-product-images', $options);

    return [$exit, Artisan::output()];
}

/** @return list<string> every SQL write the callback makes */
function demoPhotoWrites(callable $run): array
{
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });
    $run();

    return $writes;
}

/** An uploaded photo on $item, set as an admin does from the product page (origin upload). */
function demoUploadedPhoto(Item $item, int $adminId): ItemImage
{
    $branch = Branch::query()->findOrFail($item->branch_id);
    actInBranch($branch);
    withActor($adminId);
    $row = app(ProductPhotos::class)->set($item, imgJpeg(1000, 750), null, (string) Str::uuid());
    app(CurrentBranch::class)->clear();
    app(ActorContext::class)->reset();

    return $row;
}

it('attaches a demo photo to each matched product without one, through the photo pipeline, as the first active admin, and reports the rest', function () {
    demoPhotoFolder($this, [['bread-roll', ['Bread roll']], ['latte', ['Latte', 'Caffe latte']], ['croissant', ['Croissant']], ['pizza', ['Pizza']]]);
    $roll = ($this->product)('Bread roll');
    $latte = ($this->product)('caffe latte '); // matched without regard to case and the spaces around it
    $croissant = ($this->product)('Croissant');
    $mystery = ($this->product)('Mystery loaf');
    $flour = Item::factory()->ingredient()->create(['branch_id' => $this->branch->id, 'name_en' => 'Croissant', 'created_by_user_id' => $this->admin->id]);
    $uploaded = demoUploadedPhoto($croissant, $this->admin->id);
    $other = Branch::factory()->create(['settings' => ['demo' => true]]);
    $elsewhere = ($this->product)('Bread roll', $other);
    makeUser($this->branch, 'admin', ['name' => 'Second admin']);
    $version = (int) $this->branch->fresh()?->catalog_version;

    [$exit, $output] = demoPhotosRun(['--branch' => (string) $this->branch->id]);

    expect($exit)->toBe(0, $output);
    $rows = ItemImage::query()->withoutGlobalScopes()->where('origin', 'demo')->orderBy('item_id')->get();
    expect($rows->pluck('item_id')->all())->toBe([$roll->id, $latte->id])
        ->and($rows->pluck('branch_id')->unique()->all())->toBe([$this->branch->id])
        ->and($rows->pluck('uploaded_by_user_id')->unique()->all())->toBe([null])
        ->and($rows->pluck('width')->unique()->all())->toBe([1200])
        ->and($rows->pluck('height')->unique()->all())->toBe([900])
        ->and($roll->fresh()?->image_id)->toBe($rows[0]->id)
        ->and($latte->fresh()?->image_id)->toBe($rows[1]->id)
        ->and($croissant->fresh()?->image_id)->toBe($uploaded->id)
        ->and($mystery->fresh()?->image_id)->toBeNull()
        ->and($flour->fresh()?->image_id)->toBeNull()
        ->and($elsewhere->fresh()?->image_id)->toBeNull()
        ->and((int) $this->branch->fresh()?->catalog_version)->toBeGreaterThan($version);
    foreach ($rows as $row) {
        foreach (['thumb', 'card', 'large'] as $rendition) {
            expect($this->disk->exists($row->path($rendition)))->toBeTrue("{$row->item_id} {$rendition}");
        }
        expect($row->sha256)->toBe(hash('sha256', (string) $this->disk->get($row->path('large'))));
    }

    // the audit rows name the first active admin and say the photo is a demo one
    $audits = DB::table('audit_log')->where('action', 'catalog.item.image_set')->whereIn('subject_id', [$roll->id, $latte->id])->get();
    expect($audits)->toHaveCount(2)
        ->and($audits->pluck('actor_id')->unique()->all())->toBe([$this->admin->id])
        ->and($audits->pluck('branch_id')->unique()->all())->toBe([$this->branch->id]);
    foreach ($audits as $audit) {
        expect(json_decode((string) $audit->after, true)['origin'])->toBe('demo');
    }

    // per product, then the counts; the unmatched name and the entry no product matched are named
    expect($output)->toContain('Bread roll')->toContain('attached')
        ->and($output)->toContain('Croissant')->toContain('uploaded photo')
        ->and($output)->toContain('Mystery loaf')->toContain('no manifest entry')
        ->and($output)->toContain('First admin')
        ->and($output)->toMatch('/(?<!un)matched: 3\b/')
        ->and($output)->toContain('attached: 2')
        ->and($output)->toContain('skipped: 1')
        ->and($output)->toContain('unmatched: 1')
        ->and($output)->toContain('failed: 0')
        ->and($output)->toContain('pizza');
});

it('writes nothing on a second run: every matched product has its photo', function () {
    demoPhotoFolder($this, [['bread-roll', ['Bread roll']], ['latte', ['Latte']]]);
    ($this->product)('Bread roll');
    ($this->product)('Latte');
    expect(demoPhotosRun(['--branch' => (string) $this->branch->id])[0])->toBe(0);
    $files = $this->disk->allFiles();

    $output = '';
    expect(demoPhotoWrites(function () use (&$output): void {
        [$exit, $output] = demoPhotosRun(['--branch' => (string) $this->branch->id]);
        expect($exit)->toBe(0);
    }))->toBe([]);
    expect($this->disk->allFiles())->toBe($files)
        ->and(ItemImage::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and($output)->toContain('has a photo')
        ->and($output)->toContain('attached: 0')
        ->and($output)->toContain('skipped: 2');
});

it('says with --dry-run per product what it would do, and writes nothing, not even a file', function () {
    demoPhotoFolder($this, [['bread-roll', ['Bread roll']], ['latte', ['Latte']], ['croissant', ['Croissant']]]);
    ($this->product)('Bread roll');
    ($this->product)('Latte');
    $croissant = ($this->product)('Croissant');
    ($this->product)('Mystery loaf');
    demoUploadedPhoto($croissant, $this->admin->id);
    $files = $this->disk->allFiles();
    $version = (int) $this->branch->fresh()?->catalog_version;

    $outputs = [];
    $writes = demoPhotoWrites(function () use (&$outputs): void {
        foreach ([['--dry-run' => true], ['--dry-run' => true, '--force' => true]] as $options) {
            [$exit, $outputs[]] = demoPhotosRun(['--branch' => (string) $this->branch->id] + $options);
            expect($exit)->toBe(0);
        }
    });

    expect($writes)->toBe([])
        ->and($this->disk->allFiles())->toBe($files)
        ->and(ItemImage::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and((int) $this->branch->fresh()?->catalog_version)->toBe($version);
    foreach ($outputs as $output) {
        expect($output)->toContain('Dry run')
            ->and($output)->toContain('would attach')
            ->and($output)->toContain('uploaded photo')
            ->and($output)->toContain('no manifest entry')
            ->and($output)->toContain('would attach: 2')
            ->and($output)->toContain('skipped: 1')
            ->and($output)->toContain('unmatched: 1');
    }
});

it('replaces with --force only the photos it attached (origin demo); an uploaded photo stays, with or without --force', function () {
    demoPhotoFolder($this, [['bread-roll', ['Bread roll']], ['croissant', ['Croissant']]]);
    $roll = ($this->product)('Bread roll');
    $croissant = ($this->product)('Croissant');
    $uploaded = demoUploadedPhoto($croissant, $this->admin->id);
    expect(demoPhotosRun(['--branch' => (string) $this->branch->id])[0])->toBe(0);
    $first = ItemImage::query()->withoutGlobalScopes()->findOrFail($roll->fresh()?->image_id);

    // the demo photo of the slug changed: --force puts the new one on, the old row soft-deleted, the upload untouched
    demoPhotoFolder($this, [['bread-roll', ['Bread roll']], ['croissant', ['Croissant']]], null, 41);
    [$exit, $output] = demoPhotosRun(['--branch' => (string) $this->branch->id, '--force' => true]);

    $second = ItemImage::query()->withoutGlobalScopes()->findOrFail($roll->fresh()?->image_id);
    expect($exit)->toBe(0, $output)
        ->and($second->id)->not->toBe($first->id)
        ->and($second->origin)->toBe('demo')
        ->and($second->sha256)->not->toBe($first->sha256)
        ->and($first->fresh()?->trashed())->toBeTrue()
        ->and($croissant->fresh()?->image_id)->toBe($uploaded->id)
        ->and($uploaded->fresh()?->trashed())->toBeFalse()
        ->and(ItemImage::query()->withoutGlobalScopes()->where('item_id', $croissant->id)->withTrashed()->count())->toBe(1)
        ->and($output)->toContain('replaced')
        ->and($output)->toContain('uploaded photo');
});

it('runs as --as when given (of the branch, active, allowed to manage the catalog), and refuses clearly when there is no such admin', function () {
    demoPhotoFolder($this, [['bread-roll', ['Bread roll']]]);
    $roll = ($this->product)('Bread roll');
    $manager = makeUser($this->branch, 'admin', ['name' => 'Chosen admin']);
    $waiter = makeUser($this->branch, 'waiter');
    $retired = makeUser($this->branch, 'admin', ['is_active' => false]);
    $stranger = makeUser(Branch::factory()->create(), 'admin');

    foreach ([$waiter, $retired, $stranger] as $user) {
        [$exit, $output] = demoPhotosRun(['--branch' => (string) $this->branch->id, '--as' => (string) $user->id]);
        expect($exit)->toBe(1, $output)->and($output)->toContain('--as');
    }
    expect(demoPhotosRun(['--branch' => (string) $this->branch->id, '--as' => 'me'])[0])->toBe(1)
        ->and(ItemImage::query()->withoutGlobalScopes()->count())->toBe(0);

    [$exit, $output] = demoPhotosRun(['--branch' => (string) $this->branch->id, '--as' => (string) $manager->id]);
    expect($exit)->toBe(0, $output)
        ->and(DB::table('audit_log')->where('action', 'catalog.item.image_set')->where('subject_id', $roll->id)->value('actor_id'))->toBe($manager->id);

    // no active admin at all: refused before anything is written
    $bare = Branch::factory()->create(['settings' => ['demo' => true]]);
    ($this->product)('Bread roll', $bare);
    makeUser($bare, 'admin', ['is_active' => false]);
    [$exit, $output] = demoPhotosRun(['--branch' => (string) $bare->id]);
    expect($exit)->toBe(1)
        ->and($output)->toContain('no active admin')
        ->and(ItemImage::query()->withoutGlobalScopes()->where('branch_id', $bare->id)->count())->toBe(0);
});

it('refuses a missing, unknown or non-demo branch and writes nothing', function () {
    demoPhotoFolder($this, [['bread-roll', ['Bread roll']]]);
    ($this->product)('Bread roll');
    $shop = Branch::factory()->create(['name' => 'Main shop']);
    makeUser($shop, 'admin');
    Item::factory()->product()->create(['branch_id' => $shop->id, 'name_en' => 'Bread roll', 'created_by_user_id' => $this->admin->id]);

    foreach ([[], ['--branch' => 'two'], ['--branch' => '999999'], ['--branch' => (string) $shop->id]] as $options) {
        [$exit, $output] = demoPhotosRun($options);
        expect($exit)->toBe(1, json_encode($options))->and($output)->toContain('--branch');
    }
    expect(ItemImage::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($this->disk->allFiles())->toBe([]);
});

it('refuses a bad manifest whole, before anything is written', function (Closure $edit, string $says) {
    demoPhotoFolder($this, [['bread-roll', ['Bread roll']], ['latte', ['Latte']]], $edit);
    ($this->product)('Bread roll');
    ($this->product)('Latte');

    [$exit, $output] = demoPhotosRun(['--branch' => (string) $this->branch->id]);

    expect($exit)->toBe(1)
        ->and($output)->toContain($says)
        ->and(ItemImage::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($this->disk->allFiles())->toBe([]);
})->with([
    'no licence' => [fn (array $m) => [$m[0], array_diff_key($m[1], ['licence' => true])], 'licence'],
    'a blank licence' => [fn (array $m) => [$m[0], ['licence' => ' '] + $m[1]], 'licence'],
    'no source' => [fn (array $m) => [$m[0], ['source' => null] + $m[1]], 'source'],
    'a source that is not a web page' => [fn (array $m) => [$m[0], ['source' => 'a friend'] + $m[1]], 'source'],
    'a sixth key' => [fn (array $m) => [$m[0], $m[1] + ['photographer' => 'someone']], 'keys'],
    'no matches' => [fn (array $m) => [$m[0], ['matches' => []] + $m[1]], 'matches'],
    'a name in two entries' => [fn (array $m) => [$m[0], ['matches' => ['Latte', 'Bread roll']] + $m[1]], 'Bread roll'],
    'a file that is not there' => [fn (array $m) => [$m[0], ['file' => 'missing.jpg'] + $m[1]], 'missing.jpg'],
    'a file outside the folder' => [fn (array $m) => [$m[0], ['file' => '../manifest.json'] + $m[1]], 'file'],
    'not a list' => [fn (array $m) => ['photos' => $m], 'manifest'],
]);

it('refuses a photo that is not 4:3 or is under 800 x 600, before anything is written', function (int $width, int $height) {
    demoPhotoFolder($this, [['bread-roll', ['Bread roll']], ['latte', ['Latte'], $width, $height]]);
    ($this->product)('Bread roll');
    ($this->product)('Latte');

    [$exit, $output] = demoPhotosRun(['--branch' => (string) $this->branch->id]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('latte.jpg')
        ->and($output)->toContain("{$width} x {$height}")
        ->and(ItemImage::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($this->disk->allFiles())->toBe([]);
})->with([
    'wider than 4:3' => [1600, 1000],
    'a square' => [900, 900],
    'under 800 x 600' => [640, 480],
]);

it('attaches the committed photo of a product of the demo bakery (the resources folder, WebP)', function () {
    $roll = ($this->product)('Bread roll');

    [$exit, $output] = demoPhotosRun(['--branch' => (string) $this->branch->id]);

    $row = ItemImage::query()->withoutGlobalScopes()->findOrFail($roll->fresh()?->image_id);
    expect($exit)->toBe(0, $output)
        ->and([$row->origin, $row->width, $row->height, $row->mime])->toBe(['demo', 1600, 1200, 'image/jpeg'])
        ->and($this->disk->exists($row->path('card')))->toBeTrue()
        ->and(getimagesizefromstring((string) $this->disk->get($row->path('card')))[0])->toBe(800)
        ->and($output)->toContain('attached: 1');
});

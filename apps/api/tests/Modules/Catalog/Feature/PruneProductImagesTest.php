<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemImage;
use App\Modules\Catalog\Services\ProductPhotos;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Step 6.1b (phase-6 plan 4.4 and 10.2 Q19, ADR 0022): `bakery:prune-product-images {--days=30} {--dry-run}`. A replaced or removed
 * photo keeps its row (soft deletes are the history) and its files, until the prune: the files of a photo whose rows were all removed
 * more than --days ago are deleted, unless a row of any branch still uses the same sha256 (live, or removed within the window). Rows are
 * never deleted. It prints the counts and the bytes, and --dry-run deletes nothing. Daily at 03:50 Addis Ababa time, after the nightly
 * backup has hard-linked the files into its snapshot.
 */

beforeEach(function () {
    Storage::fake(config('catalog.images.disk'));
    config(['catalog.images.format' => 'jpeg']);
    seedRoles();
    $this->start = CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC');
    $this->travelTo($this->start);
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    $product = fn (Branch $branch, string $name): Item => Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $branch->id, 'name_en' => $name]);
    $this->cake = $product($this->branch, 'Black forest cake');
    $this->latte = $product($this->branch, 'Latte');
    $this->sandwich = $product($this->branch, 'Chicken sandwich');
    $this->product = $product;
    $this->set = fn (Item $item, string $bytes): ItemImage => app(ProductPhotos::class)->set($item->fresh() ?? $item, $bytes, null, (string) Str::uuid());
    $this->remove = fn (Item $item): bool => app(ProductPhotos::class)->remove($item->fresh() ?? $item);
    $this->files = function (ItemImage $row): array {
        $disk = Storage::disk($row->disk);

        return array_values(array_filter(['thumb', 'card', 'large'], fn (string $r): bool => $disk->exists($row->path($r))));
    };
    $this->prune = function (array $options = []): array {
        $code = Artisan::call('bakery:prune-product-images', $options);

        return [$code, trim(Artisan::output())];
    };
});

it('deletes the files of a photo removed more than 30 days ago and keeps its row; a photo removed 29 days ago and a live one keep their files', function () {
    $removed = ($this->set)($this->cake, imgJpeg(1200, 900));
    ($this->remove)($this->cake);
    $replaced = ($this->set)($this->latte, imgJpeg(1000, 750));
    $this->travelTo($this->start->addDays(2));
    $current = ($this->set)($this->latte, imgJpeg(1400, 1050)); // replaces $replaced two days later
    $live = ($this->set)($this->sandwich, imgJpeg(800, 600));
    $rows = ItemImage::query()->withTrashed()->count();

    $this->travelTo($this->start->addDays(31));
    [$code, $out] = ($this->prune)();

    expect($code)->toBe(0, $out)
        ->and(($this->files)($removed))->toBe([], 'removed 31 days ago: its three files are gone')
        ->and(($this->files)($replaced))->toBe(['thumb', 'card', 'large'], 'replaced 29 days ago: kept')
        ->and(($this->files)($current))->toBe(['thumb', 'card', 'large'])
        ->and(($this->files)($live))->toBe(['thumb', 'card', 'large'])
        ->and(ItemImage::query()->withTrashed()->count())->toBe($rows, 'rows are history: never deleted')
        ->and(ItemImage::query()->withTrashed()->findOrFail($removed->id)->trashed())->toBeTrue()
        ->and(Storage::disk($removed->disk)->directoryExists($removed->key_prefix))->toBeFalse('its folder goes with its files')
        ->and($out)->toContain('1 pruned (3 files, '.$removed->bytes.' bytes)')
        ->and($out)->toContain('1 removed photo older than 30 days');

    [$code, $out] = ($this->prune)(['--days' => 28]);
    expect($code)->toBe(0, $out)
        ->and(($this->files)($replaced))->toBe([], '--days=28 reaches the photo replaced 29 days ago')
        ->and(($this->files)($current))->toBe(['thumb', 'card', 'large'])
        ->and($out)->toContain('1 pruned (3 files, '.$replaced->bytes.' bytes)')
        ->and($out)->toContain('1 already pruned');

    [$code, $out] = ($this->prune)(['--days' => 28]);
    expect($code)->toBe(0, $out)
        ->and($out)->toContain('0 pruned (0 files, 0 bytes)')
        ->and($out)->toContain('2 already pruned');
})->group('module:catalog', 'feature');

it('keeps the files of a removed photo while a row of any branch uses the same sha256, and prunes them once the last row is past the window', function () {
    $mine = ($this->set)($this->cake, imgJpeg(1200, 900));
    $other = actInBranch(Branch::factory()->create());
    withActor(makeUser($other, 'admin')->id);
    $theirCake = ($this->product)($other, 'Their cake');
    $theirs = ($this->set)($theirCake, imgJpeg(1200, 900));
    expect($theirs->sha256)->toBe($mine->sha256, 'the same photo: one set of files');

    actInBranch($this->branch);
    withActor($this->admin->id);
    ($this->remove)($this->cake);
    $this->travelTo($this->start->addDays(31));
    actInBranch(Branch::factory()->create()); // the command runs with no branch of its own: it reads every branch

    [$code, $out] = ($this->prune)();
    expect($code)->toBe(0, $out)
        ->and(($this->files)($mine))->toBe(['thumb', 'card', 'large'], 'another branch still shows it')
        ->and($out)->toContain('1 kept (still in use)')
        ->and($out)->toContain('0 pruned');
    $this->get('/api/v1/media/items/'.$mine->sha256.'/card.jpg')->assertOk();

    actInBranch($other);
    withActor(makeUser($other, 'admin')->id);
    ($this->remove)($theirCake);
    $this->travelTo($this->start->addDays(40));
    [, $out] = ($this->prune)();
    expect(($this->files)($mine))->toBe(['thumb', 'card', 'large'], 'its last row was removed 9 days ago: still inside the window')
        ->and($out)->toContain('1 kept (still in use)');

    $this->travelTo($this->start->addDays(62));
    [$code, $out] = ($this->prune)();
    expect($code)->toBe(0, $out)
        ->and(($this->files)($mine))->toBe([], 'every row that named it is past the window')
        ->and($out)->toContain('1 pruned (3 files, '.$mine->bytes.' bytes)');
    $this->get('/api/v1/media/items/'.$mine->sha256.'/card.jpg')->assertNotFound();
    expect(ItemImage::query()->withoutGlobalScope(BranchScope::class)->withTrashed()->whereKey([$mine->id, $theirs->id])->count())->toBe(2);
})->group('module:catalog', 'feature');

it('deletes nothing with --dry-run and says what it would prune', function () {
    $removed = ($this->set)($this->cake, imgJpeg(1200, 900));
    ($this->remove)($this->cake);
    $this->travelTo($this->start->addDays(31));

    [$code, $out] = ($this->prune)(['--dry-run' => true]);

    expect($code)->toBe(0, $out)
        ->and(($this->files)($removed))->toBe(['thumb', 'card', 'large'], 'a dry run deletes nothing')
        ->and($out)->toContain('dry run, nothing deleted')
        ->and($out)->toContain('1 would be pruned (3 files, '.$removed->bytes.' bytes)');

    [$code, $out] = ($this->prune)();
    expect($code)->toBe(0, $out)
        ->and(($this->files)($removed))->toBe([])
        ->and($out)->not->toContain('dry run');
})->group('module:catalog', 'feature');

it('refuses a --days below 1', function () {
    $removed = ($this->set)($this->cake, imgJpeg(1200, 900));
    ($this->remove)($this->cake);

    foreach (['0', '-3', 'soon'] as $days) {
        [$code] = ($this->prune)(['--days' => $days]);
        expect($code)->toBe(2, "--days={$days}: invalid");
    }
    expect(($this->files)($removed))->toBe(['thumb', 'card', 'large']);
})->group('module:catalog', 'feature');

it('prints no path, key prefix or sha256', function () {
    $removed = ($this->set)($this->cake, imgJpeg(1200, 900));
    ($this->remove)($this->cake);
    $this->travelTo($this->start->addDays(31));

    [, $out] = ($this->prune)();

    expect($out)->not->toContain($removed->key_prefix)->not->toContain($removed->sha256)->not->toContain(storage_path());
})->group('module:catalog', 'feature');

it('is scheduled daily at 03:50 Addis Ababa time, after the nightly backup, and never twice at once', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'bakery:prune-product-images'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('50 3 * * *')
        ->and((string) $event->timezone)->toBe('Africa/Addis_Ababa')
        ->and($event->withoutOverlapping)->toBeTrue();
})->group('module:catalog', 'feature');

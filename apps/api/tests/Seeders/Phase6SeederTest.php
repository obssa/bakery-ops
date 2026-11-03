<?php

declare(strict_types=1);

use App\Console\Support\DemoProductImagesRefused;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemImage;
use App\Modules\Catalog\Services\ProductPhotos;
use App\Modules\Identity\Models\User;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Tenancy\CurrentBranch;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\Demo\DemoSeedRefused;
use Database\Seeders\DemoSeeder;
use Database\Seeders\Phase0Seeder;
use Database\Seeders\Phase5Seeder;
use Database\Seeders\Phase6Seeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

pest()->group('feature', 'seeds');

/*
 * Phase 6 of the demo bakery (phase-6 plan 8, step 6.1c). DemoSeeder composes it after Phase5Seeder when SEED_PHASE is 6 or unset. In the
 * demo branch only, it attaches the demo photo of every product resources/demo/product-images/manifest.json matches by name_en and that
 * has no photo, with origin `demo`, through the same code as an upload (Catalog's ProductPhotos, through bakery:demo-product-images'
 * service), as the branch's first active admin. A product with an uploaded photo keeps it; a second run writes nothing; it writes no stock
 * movement, order, payment or shift, so no earlier gate's figures move.
 */

beforeEach(function () {
    Storage::fake(config('catalog.images.disk'));
    config(['catalog.images.format' => 'jpeg']); // the e2e stack writes WebP (auto); JPEG keeps this test quick
});

afterEach(function () {
    putenv('SEED_PHASE');
    app(CurrentBranch::class)->clear();
    app(ActorContext::class)->reset();
});

/** @return list<string> every SQL write the callback makes */
function phase6Writes(callable $run): array
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

it('composes Phase 6 after Phase 5, the last phase', function () {
    $seeders = DemoSeeder::seedersUpTo(6);

    expect(DemoSeeder::LAST_PHASE)->toBe(6)
        ->and(end($seeders))->toBe(Phase6Seeder::class)
        ->and($seeders[count($seeders) - 2])->toBe(Phase5Seeder::class)
        ->and(DemoSeeder::seedersUpTo(5))->not->toContain(Phase6Seeder::class);
});

it('gives every demo product its photo, keeps an uploaded one, touches no other branch and no ledger, and writes nothing the second time', function () {
    putenv('SEED_PHASE=2'); // Phase 0's catalog and Phase 2's gate product: the 29 products of the demo bakery
    $this->seed(DemoSeeder::class);
    putenv('SEED_PHASE');
    $branch = Phase0Seeder::existingBranch();
    $admin = User::query()->withoutGlobalScope(BranchScope::class)->where('phone', DemoBakery::ADMIN_PHONE)->sole();
    $products = fn () => Item::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $branch->id)
        ->where('kind', ItemKind::Product)->orderBy('id')->get();
    expect($products())->toHaveCount(29);

    // the admin has already put a photo of the real croissant on
    $croissant = $products()->firstWhere('name_en', 'Croissant');
    actInBranch($branch);
    withActor($admin->id);
    $uploaded = app(ProductPhotos::class)->set($croissant, imgJpeg(1200, 900), null, (string) Str::uuid());
    app(CurrentBranch::class)->clear();
    app(ActorContext::class)->reset();

    // another branch with a product of the same name
    $shop = Branch::factory()->create(['name' => 'Main shop']);
    $shopRoll = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $shop->id, 'name_en' => 'Bread roll', 'created_by_user_id' => $admin->id]);
    $untouched = fn (): array => [
        DB::table('stock_movements')->count(), DB::table('orders')->count(), DB::table('payments')->count(), DB::table('shift_instances')->count(),
    ];
    $ledger = $untouched();

    ob_start();
    (new Phase6Seeder)->run();
    $printed = (string) ob_get_clean();

    $all = $products();
    $images = ItemImage::query()->withoutGlobalScope(BranchScope::class)->whereIn('id', $all->pluck('image_id')->filter())->get()->keyBy('item_id');
    expect($all->whereNull('image_id'))->toBeEmpty()
        ->and($images)->toHaveCount(29)
        ->and($images[$croissant->id]->id)->toBe($uploaded->id)
        ->and($images[$croissant->id]->origin)->toBe('upload')
        ->and($images->except([$croissant->id])->pluck('origin')->unique()->all())->toBe(['demo'])
        ->and($images->pluck('branch_id')->unique()->all())->toBe([$branch->id])
        ->and($images->except([$croissant->id])->pluck('uploaded_by_user_id')->unique()->all())->toBe([null])
        ->and([$images->first()->width, $images->first()->height])->toBe([1600, 1200])
        ->and($shopRoll->fresh()?->image_id)->toBeNull()
        ->and(ItemImage::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $shop->id)->count())->toBe(0)
        ->and($untouched())->toBe($ledger)
        ->and($printed)->toBe('');
    // every demo photo through the pipeline (three files under its sha256) and audited as the demo admin
    $disk = Storage::disk(config('catalog.images.disk'));
    foreach ($images->except([$croissant->id]) as $image) {
        expect($disk->exists($image->path('thumb')) && $disk->exists($image->path('card')) && $disk->exists($image->path('large')))->toBeTrue();
    }
    $audits = DB::table('audit_log')->where('action', 'catalog.item.image_set')->where('branch_id', $branch->id)->get()
        ->filter(fn (object $row): bool => json_decode((string) $row->after, true)['origin'] === 'demo');
    expect($audits)->toHaveCount(28)
        ->and($audits->pluck('actor_id')->unique()->all())->toBe([$admin->id]);

    // a second run finds a photo on every product and writes nothing
    $files = $disk->allFiles();
    expect(phase6Writes(fn () => (new Phase6Seeder)->run()))->toBe([])
        ->and($disk->allFiles())->toBe($files)
        ->and(app(CurrentBranch::class)->isResolved())->toBeFalse()
        ->and(app(ActorContext::class)->isAuthenticated())->toBeFalse();
});

it('refuses before writing when the demo branch is missing or has no active admin to name in the audit log', function () {
    expect(fn () => (new Phase6Seeder)->run())->toThrow(DemoSeedRefused::class);

    app(Phase0Seeder::class)->run();
    $branch = Phase0Seeder::existingBranch();
    User::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $branch->id)->update(['is_active' => false]);
    app(CurrentBranch::class)->clear();
    app(ActorContext::class)->reset();

    $writes = phase6Writes(function (): void {
        expect(fn () => (new Phase6Seeder)->run())->toThrow(DemoProductImagesRefused::class, 'no active admin');
    });
    expect($writes)->toBe([])
        ->and(ItemImage::query()->withoutGlobalScope(BranchScope::class)->count())->toBe(0);
});

<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemImage;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Step 6.1a (phase-6 plan 4.1, ADR 0022): DELETE /api/v1/items/{item}/image takes the display photo off a product. Admin only
 * (catalog.manage), idempotent (rule 7). It soft-deletes the current row (its files wait for the prune of 6.1b), clears items.image_id,
 * moves catalog_version and audits catalog.item.image_removed with the old id. A product without a photo gets the same answer, and
 * nothing is written: no audit row, no new menu ETag.
 */

beforeEach(function () {
    Storage::fake(config('catalog.images.disk'));
    config(['catalog.images.format' => 'jpeg']);
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = loginAs(makeUser($this->branch, 'admin'));
    withActor($this->admin->id);
    $this->cake = Item::factory()->product(Fulfilment::ToStock, 'slice')->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->admin->id]);
    $this->image = photoPost($this, $this->cake->id, imgJpeg(1000, 750))->assertCreated()->json('data.image');
    $this->version = fn (): int => (int) app(Items::class)->menuFingerprint()['catalog_version'];
    $this->remove = fn (int $itemId, ?string $key = null) => $this->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])
        ->deleteJson("/api/v1/items/{$itemId}/image");
});

it('removes the photo: 200 with image null, the row soft-deleted, the item without a photo, its URLs gone, one audit row, a new catalog_version', function () {
    $before = ($this->version)();

    ($this->remove)($this->cake->id)->assertOk()->assertExactJson(['data' => ['item_id' => $this->cake->id, 'image' => null]]);

    $row = ItemImage::query()->withTrashed()->findOrFail($this->image['id']);
    $audit = AuditLog::query()->where('action', 'catalog.item.image_removed')->sole();
    expect($row->trashed())->toBeTrue()
        ->and($this->cake->fresh()?->image_id)->toBeNull()
        ->and(($this->version)())->toBeGreaterThan($before)
        ->and($audit->subject_id)->toBe($this->cake->id)
        ->and($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->after)->toEqual(['item_id' => $this->cake->id, 'before_image_id' => $this->image['id']])
        ->and(photoFile($this->image['large']))->not->toBeNull('the files wait for the prune (6.1b)');

    $this->getJson("/api/v1/items/{$this->cake->id}")->assertOk()->assertJsonPath('image', null);
    foreach (['thumb', 'card', 'large'] as $rendition) {
        $this->get($this->image[$rendition])->assertNotFound();
    }
})->group('module:catalog', 'feature');

it('answers the same for a product without a photo or an ingredient, and writes nothing: no audit row, no new catalog_version', function () {
    ($this->remove)($this->cake->id)->assertOk();
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    $version = ($this->version)();
    $audits = AuditLog::query()->count();

    ($this->remove)($this->cake->id)->assertOk()->assertExactJson(['data' => ['item_id' => $this->cake->id, 'image' => null]]);
    ($this->remove)($flour->id)->assertOk()->assertExactJson(['data' => ['item_id' => $flour->id, 'image' => null]]);

    expect(($this->version)())->toBe($version)
        ->and(AuditLog::query()->count())->toBe($audits);
})->group('module:catalog', 'feature');

it('replays the same Idempotency-Key with the stored answer and one audit row', function () {
    $key = (string) Str::uuid();

    $first = ($this->remove)($this->cake->id, $key)->assertOk();
    $replay = ($this->remove)($this->cake->id, $key)->assertOk();

    expect($replay->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and($replay->json())->toEqual($first->json())
        ->and(AuditLog::query()->where('action', 'catalog.item.image_removed')->count())->toBe(1);
})->group('module:catalog', 'feature');

it('answers 404 for another branch\'s item, a deleted item and an unknown id, and 403 to every role but the admin', function () {
    $theirs = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => Branch::factory()->create()->id]);
    $gone = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);
    $gone->delete();

    ($this->remove)($theirs->id)->assertNotFound();
    ($this->remove)($gone->id)->assertNotFound();
    ($this->remove)(987654)->assertNotFound();
    foreach (array_diff(identityRoles(), ['admin']) as $role) {
        loginAs(makeUser($this->branch, $role));
        ($this->remove)($this->cake->id)->assertForbidden();
    }

    expect($this->cake->fresh()?->image_id)->toBe($this->image['id'])
        ->and(AuditLog::query()->where('action', 'catalog.item.image_removed')->count())->toBe(0);
})->group('module:catalog', 'feature');

<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Contracts\Channels;
use App\Modules\Payments\Contracts\ChannelView;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Models\Branch;

/*
 * GET /payment-channels (phase-1 plan section 4; payments.take): the branch's channels in their order, with
 * accepted_now saying whether the till can take one today (Phase 1: cash only; the digital channels need a receipt
 * photo, Phase 4). Also the provided capability Channels (manifest payments.provides) the till and Phase 4 read.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    $this->cashier = makeUser($this->branch, 'cashier');
    withActor($this->admin->id);
    $this->get = function (?User $as = null) {
        loginAs($as ?? $this->cashier);

        return $this->getJson('/api/v1/payment-channels');
    };
});

it('lists the eight default channels in their order, with what the till needs to know', function () {
    $response = ($this->get)()->assertOk();

    $data = $response->json('data');
    expect(array_column($data, 'code'))->toBe(['cash', 'telebirr', 'cbe_birr', 'cbe_bank', 'awash', 'dashen', 'abyssinia', 'coop'])
        ->and(array_column($data, 'sort'))->toBe([1, 2, 3, 4, 5, 6, 7, 8])
        ->and(array_keys($data[0]))->toBe(['id', 'code', 'name_en', 'name_am', 'is_enabled', 'requires_photo', 'requires_reference', 'sort', 'accepted_now'])
        ->and($data[0])->toMatchArray(['code' => 'cash', 'name_en' => 'Cash', 'is_enabled' => true, 'requires_photo' => false, 'requires_reference' => false, 'accepted_now' => true]);
    foreach (array_slice($data, 1) as $channel) {
        expect($channel['is_enabled'])->toBeTrue()
            ->and($channel['requires_photo'])->toBeTrue()
            ->and($channel['requires_reference'])->toBeTrue()
            ->and($channel['accepted_now'])->toBeFalse("{$channel['code']} needs a receipt photo (Phase 4)");
    }
    foreach ($data as $channel) {
        expect($channel['name_en'])->toBeString()->not->toBeEmpty()
            ->and($channel['name_am'])->toMatch('/\p{Ethiopic}/u')
            ->and($channel['name_am'])->not->toBe($channel['name_en']);
    }
})->group('module:payments', 'feature');

it('shows a disabled channel as such and never accepts it now; a removed channel is not listed', function () {
    PaymentChannel::query()->where('code', 'cash')->update(['is_enabled' => false]);
    PaymentChannel::query()->where('code', 'coop')->delete();

    $data = ($this->get)()->assertOk()->json('data');

    expect(array_column($data, 'code'))->not->toContain('coop')
        ->and($data[0])->toMatchArray(['code' => 'cash', 'is_enabled' => false, 'accepted_now' => false]);
})->group('module:payments', 'feature');

it('lists only the caller\'s branch', function () {
    $other = Branch::factory()->create();
    PaymentChannel::query()->withoutGlobalScopes()->where('branch_id', $other->id)->where('code', 'cash')->update(['name_en' => 'Other branch cash']);

    $data = ($this->get)()->assertOk()->json('data');

    expect($data)->toHaveCount(8)->and($data[0]['name_en'])->toBe('Cash');
    $ids = array_column($data, 'id');
    expect(PaymentChannel::query()->withoutGlobalScopes()->whereIn('id', $ids)->pluck('branch_id')->unique()->all())->toBe([$this->branch->id]);
})->group('module:payments', 'feature');

it('answers Payments\\Contracts\\Channels: the enabled channels of the branch, and the defaults on demand', function () {
    PaymentChannel::query()->where('code', 'awash')->update(['is_enabled' => false]);
    $channels = app(Channels::class);

    $enabled = $channels->enabled();

    expect($enabled)->toHaveCount(7)
        ->and($enabled[0])->toBeInstanceOf(ChannelView::class)
        ->and(array_map(fn (ChannelView $c): string => $c->code, $enabled))->not->toContain('awash')
        ->and($enabled[0]->acceptedNow())->toBeTrue()
        ->and($enabled[1]->acceptedNow())->toBeFalse()
        ->and($channels->find('telebirr')?->requiresPhoto)->toBeTrue()
        ->and($channels->find('barter'))->toBeNull();

    // ensureDefaults is idempotent and never touches what the admin changed
    $fresh = Branch::factory()->create();
    PaymentChannel::query()->withoutGlobalScopes()->where('branch_id', $fresh->id)->forceDelete();
    expect($channels->ensureDefaults($fresh->id))->toBe(8)
        ->and($channels->ensureDefaults($fresh->id))->toBe(0)
        ->and(PaymentChannel::query()->withoutGlobalScopes()->where('branch_id', $fresh->id)->count())->toBe(8);
})->group('module:payments', 'feature');

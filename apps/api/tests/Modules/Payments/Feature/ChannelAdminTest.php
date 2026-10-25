<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Contracts\Channels;
use App\Modules\Payments\Contracts\ChannelView;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Models\Branch;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrderFloor;

/*
 * Payment channel administration (phase-4 plan section 4.2 and section 6 item 3, step 4.3a; channels.manage, the admin):
 *
 *   GET    /payment-channels             payments.take; ?all=1 (channels.manage) adds the removed channels, with deleted_at
 *   POST   /payment-channels             201 Channel; 200 the same channel for a client_uuid already used;
 *                                        422 payments.channel.code_taken / payments.channel.pattern_invalid / validation.failed
 *   PATCH  /payment-channels/{channel}   200 Channel; the code never changes; 409 payments.channel.cash_locked
 *   DELETE /payment-channels/{channel}   200 Channel with deleted_at (soft); 409 payments.channel.cash_locked
 *
 * (PUT /payment-channels/order is ChannelOrderTest.) The cash channel keeps the till working whatever the admin does: it cannot be
 * switched off, removed, or made to ask for a photo, a reference or a reference pattern (the drawer count is built on it). Every
 * write is one AuditedAction. Channels::enabled() keeps its Phase 1 shape; the new fields are additions. The till does not change in
 * this step: a channel that needs a photo is still refused (step 4.3b builds the attach) and the pattern is stored, not yet applied.
 */

const CHANNEL_KEYS = [
    'id', 'code', 'name_en', 'name_am', 'is_enabled', 'requires_photo', 'requires_reference', 'sort', 'accepted_now',
    'reference_pattern', 'reference_hint_en', 'reference_hint_am', 'photo_hint_en', 'photo_hint_am', 'is_cash', 'deleted_at',
];

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create(['name' => 'Bole Bakery']));
    $this->admin = makeUser($this->branch, 'admin');
    $this->manager = makeUser($this->branch, 'manager');
    $this->cashier = makeUser($this->branch, 'cashier');
    withActor($this->admin->id);
    $this->api = function (string $method, string $uri, array $body = [], ?User $as = null, ?string $key = null): TestResponse {
        loginAs($as ?? $this->admin);

        return $this->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])->json($method, $uri, $body);
    };
    $this->channel = fn (string $code): PaymentChannel => PaymentChannel::query()->withTrashed()->where('code', $code)->sole();
    $this->wallet = fn (array $extra = []): array => $extra + [
        'client_uuid' => (string) Str::uuid(),
        'code' => 'amole',
        'name_en' => 'Amole',
        'name_am' => 'አሞሌ',
        'requires_photo' => true,
        'requires_reference' => true,
        'reference_pattern' => '[A-Z0-9]{10}',
        'reference_hint_en' => 'Ten letters or digits, as on the SMS',
        'reference_hint_am' => 'በኤስኤምኤሱ ላይ እንዳለው አሥር ፊደላት ወይም አሃዞች',
        'photo_hint_en' => 'A photo of the confirmation screen',
        'photo_hint_am' => 'የማረጋገጫ ገጹ ፎቶ',
    ];
});

it('creates a channel with its rules and hints, last in the order, audited', function () {
    $body = ($this->wallet)();

    $response = ($this->api)('POST', '/api/v1/payment-channels', $body)->assertCreated();

    expect(array_keys($response->json()))->toBe(CHANNEL_KEYS);
    $response->assertJson([
        'code' => 'amole', 'name_en' => 'Amole', 'name_am' => 'አሞሌ', 'is_enabled' => true, 'requires_photo' => true, 'requires_reference' => true,
        'sort' => 9, 'accepted_now' => false, 'reference_pattern' => '[A-Z0-9]{10}', 'reference_hint_en' => 'Ten letters or digits, as on the SMS',
        'reference_hint_am' => 'በኤስኤምኤሱ ላይ እንዳለው አሥር ፊደላት ወይም አሃዞች', 'photo_hint_en' => 'A photo of the confirmation screen',
        'photo_hint_am' => 'የማረጋገጫ ገጹ ፎቶ', 'is_cash' => false, 'deleted_at' => null,
    ]);
    $row = ($this->channel)('amole');
    expect($row->id)->toBe($response->json('id'))
        ->and($row->branch_id)->toBe($this->branch->id)
        ->and($row->client_uuid)->toBe($body['client_uuid'])
        ->and($row->created_by_user_id)->toBe($this->admin->id);
    $audit = AuditLog::query()->where('action', 'payment_channel.create')->sole();
    expect($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->subject_id)->toBe($row->id)
        ->and($audit->after['code'] ?? null)->toBe('amole');

    $listed = ($this->api)('GET', '/api/v1/payment-channels', [], $this->cashier)->assertOk()->json('data');
    expect(array_column($listed, 'code'))->toBe(['cash', 'telebirr', 'cbe_birr', 'cbe_bank', 'awash', 'dashen', 'abyssinia', 'coop', 'amole'])
        ->and(array_keys($listed[0]))->toBe(CHANNEL_KEYS)
        ->and($listed[0])->toMatchArray(['code' => 'cash', 'is_cash' => true, 'reference_pattern' => null, 'reference_hint_en' => null, 'deleted_at' => null]);
})->group('module:payments', 'feature');

it('creates a channel switched off, at a given place, with no rules and no hints', function () {
    $response = ($this->api)('POST', '/api/v1/payment-channels', [
        'client_uuid' => (string) Str::uuid(), 'code' => 'house-account', 'name_en' => 'House account', 'name_am' => 'የቤት ሂሳብ',
        'requires_photo' => false, 'requires_reference' => false, 'is_enabled' => false, 'sort' => 3,
    ])->assertCreated();

    $response->assertJson(['code' => 'house-account', 'is_enabled' => false, 'accepted_now' => false, 'sort' => 3, 'reference_pattern' => null, 'photo_hint_am' => null]);
    expect(array_map(fn (ChannelView $c): string => $c->code, app(Channels::class)->enabled()))->not->toContain('house-account');
})->group('module:payments', 'feature');

it('answers the same channel for a client_uuid already used, and refuses a code the branch already has, removed ones included', function () {
    $body = ($this->wallet)();
    $first = ($this->api)('POST', '/api/v1/payment-channels', $body)->assertCreated();

    // a retry with a fresh Idempotency-Key (the first answer was lost): the same channel, nothing new
    ($this->api)('POST', '/api/v1/payment-channels', $body)->assertOk()->assertJsonPath('id', $first->json('id'));
    expect(PaymentChannel::query()->count())->toBe(9);

    ($this->api)('POST', '/api/v1/payment-channels', ($this->wallet)(['code' => 'telebirr']))
        ->assertStatus(422)->assertJsonPath('message_key', 'payments.channel.code_taken')->assertJsonPath('field', 'code')->assertJsonPath('code', 'telebirr');
    ($this->api)('DELETE', '/api/v1/payment-channels/'.($this->channel)('coop')->id)->assertOk();
    ($this->api)('POST', '/api/v1/payment-channels', ($this->wallet)(['code' => 'coop']))
        ->assertStatus(422)->assertJsonPath('message_key', 'payments.channel.code_taken');
    expect(PaymentChannel::query()->withTrashed()->count())->toBe(9);

    // another branch may use the code
    $other = Branch::factory()->create();
    $otherAdmin = makeUser($other, 'admin');
    actInBranch($other);
    ($this->api)('POST', '/api/v1/payment-channels', ($this->wallet)(), $otherAdmin)->assertCreated()->assertJsonPath('code', 'amole');
})->group('module:payments', 'feature');

it('validates the request', function (array $change, string $field) {
    $body = ($this->wallet)();
    foreach ($change as $key => $value) {
        if ($value === '__unset__') {
            unset($body[$key]);
        } else {
            $body[$key] = $value;
        }
    }

    $response = ($this->api)('POST', '/api/v1/payment-channels', $body)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');

    expect(array_keys($response->json('errors')))->toContain($field)
        ->and(PaymentChannel::query()->withTrashed()->count())->toBe(8);
})->with([
    'no client_uuid' => [['client_uuid' => '__unset__'], 'client_uuid'],
    'a client_uuid that is not one' => [['client_uuid' => 'abc'], 'client_uuid'],
    'no code' => [['code' => '__unset__'], 'code'],
    'a code with capitals' => [['code' => 'Amole'], 'code'],
    'a code with a space' => [['code' => 'amole pay'], 'code'],
    'a code of 33 characters' => [['code' => str_repeat('a', 33)], 'code'],
    'no English name' => [['name_en' => '__unset__'], 'name_en'],
    'an empty Amharic name' => [['name_am' => '   '], 'name_am'],
    'a name of 121 characters' => [['name_en' => str_repeat('n', 121)], 'name_en'],
    'no photo rule' => [['requires_photo' => '__unset__'], 'requires_photo'],
    'a reference rule that is not a boolean' => [['requires_reference' => 'yes'], 'requires_reference'],
    'a pattern that is not a string' => [['reference_pattern' => ['\d+']], 'reference_pattern'],
    'an English hint without its Amharic twin' => [['reference_hint_am' => '__unset__'], 'reference_hint_am'],
    'an Amharic photo hint without its English twin' => [['photo_hint_en' => null], 'photo_hint_en'],
    'a hint of 121 characters' => [['photo_hint_en' => str_repeat('h', 121)], 'photo_hint_en'],
    'a negative sort' => [['sort' => -1], 'sort'],
    'a sort beyond the column' => [['sort' => 40000], 'sort'],
])->group('module:payments', 'feature');

it('refuses a reference pattern that does not compile, is too long or can backtrack catastrophically, and says why', function (string $pattern, string $reason) {
    $response = ($this->api)('POST', '/api/v1/payment-channels', ($this->wallet)(['reference_pattern' => $pattern]))->assertStatus(422);

    $response->assertJsonPath('message_key', 'payments.channel.pattern_invalid')
        ->assertJsonPath('field', 'reference_pattern')
        ->assertJsonPath('reason', $reason);
    expect($response->json('message_en'))->toBeString()->not->toBeEmpty()
        ->and($response->json('message_am'))->toMatch('/\p{Ethiopic}/u')
        ->and(PaymentChannel::query()->withTrashed()->count())->toBe(8);

    $telebirr = ($this->channel)('telebirr');
    ($this->api)('PATCH', "/api/v1/payment-channels/{$telebirr->id}", ['reference_pattern' => $pattern])
        ->assertStatus(422)->assertJsonPath('message_key', 'payments.channel.pattern_invalid')->assertJsonPath('reason', $reason);
    expect($telebirr->fresh()?->reference_pattern)->toBeNull();
})->with([
    'catastrophic' => ['(a+)+$', 'nested_quantifier'],
    'a repeated overlapping choice' => ['(a|aa)+', 'nested_quantifier'],
    'does not compile' => ['[A-Z', 'does_not_compile'],
    'too long' => [str_repeat('A', 121), 'too_long'],
    'a lookahead' => ['(?=FT)FT\d+', 'unsupported'],
])->group('module:payments', 'feature');

it('renames a channel in both languages and changes its rules and hints; an empty pattern clears it', function () {
    $telebirr = ($this->channel)('telebirr');

    $response = ($this->api)('PATCH', "/api/v1/payment-channels/{$telebirr->id}", [
        'name_en' => 'Telebirr wallet', 'name_am' => 'ቴሌብር ቦርሳ', 'requires_photo' => false, 'requires_reference' => true,
        'reference_pattern' => '[A-Z0-9]{10}', 'reference_hint_en' => 'Ten characters', 'reference_hint_am' => 'አሥር ቁምፊዎች',
    ])->assertOk();

    expect(array_keys($response->json()))->toBe(CHANNEL_KEYS);
    $response->assertJson([
        'id' => $telebirr->id, 'code' => 'telebirr', 'name_en' => 'Telebirr wallet', 'name_am' => 'ቴሌብር ቦርሳ', 'requires_photo' => false,
        'requires_reference' => true, 'accepted_now' => true, 'reference_pattern' => '[A-Z0-9]{10}', 'reference_hint_en' => 'Ten characters',
        'reference_hint_am' => 'አሥር ቁምፊዎች', 'sort' => 2,
    ]);
    $audit = AuditLog::query()->where('action', 'payment_channel.update')->sole();
    expect($audit->subject_id)->toBe($telebirr->id)
        ->and($audit->before['name_en'] ?? null)->toBe('Telebirr')
        ->and($audit->after['name_en'] ?? null)->toBe('Telebirr wallet');

    ($this->api)('PATCH', "/api/v1/payment-channels/{$telebirr->id}", ['reference_pattern' => '', 'reference_hint_en' => null, 'reference_hint_am' => null])
        ->assertOk()->assertJson(['reference_pattern' => null, 'reference_hint_en' => null, 'reference_hint_am' => null, 'name_en' => 'Telebirr wallet']);
    expect(($this->channel)('telebirr')->reference_pattern)->toBeNull();

    // a hint pair is changed together
    ($this->api)('PATCH', "/api/v1/payment-channels/{$telebirr->id}", ['photo_hint_en' => 'The SMS'])
        ->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
})->group('module:payments', 'feature');

it('switches a channel off and on again', function () {
    $awash = ($this->channel)('awash');

    ($this->api)('PATCH', "/api/v1/payment-channels/{$awash->id}", ['is_enabled' => false])->assertOk()->assertJson(['is_enabled' => false, 'accepted_now' => false]);

    $listed = ($this->api)('GET', '/api/v1/payment-channels', [], $this->cashier)->assertOk()->json('data');
    expect(collect($listed)->firstWhere('code', 'awash'))->toMatchArray(['is_enabled' => false])
        ->and(array_map(fn (ChannelView $c): string => $c->code, app(Channels::class)->enabled()))->not->toContain('awash');

    ($this->api)('PATCH', "/api/v1/payment-channels/{$awash->id}", ['is_enabled' => true])->assertOk()->assertJson(['is_enabled' => true]);
    expect(array_map(fn (ChannelView $c): string => $c->code, app(Channels::class)->enabled()))->toContain('awash');
})->group('module:payments', 'feature');

it('never changes a channel\'s code', function () {
    $telebirr = ($this->channel)('telebirr');

    ($this->api)('PATCH', "/api/v1/payment-channels/{$telebirr->id}", ['code' => 'tele', 'name_en' => 'Tele'])
        ->assertStatus(422)->assertJsonPath('message_key', 'validation.failed')->assertJsonStructure(['errors' => ['code']]);

    expect(($this->channel)('telebirr')->name_en)->toBe('Telebirr');
})->group('module:payments', 'feature');

it('keeps cash able to take money: no switch-off, no removal, no photo, reference or pattern rule', function (array $change, string $field) {
    $cash = ($this->channel)('cash');

    ($this->api)('PATCH', "/api/v1/payment-channels/{$cash->id}", $change)
        ->assertStatus(409)
        ->assertJsonPath('message_key', 'payments.channel.cash_locked')
        ->assertJsonPath('field', $field)
        ->assertJsonPath('channel_code', 'cash');

    $fresh = ($this->channel)('cash');
    expect($fresh->is_enabled)->toBeTrue()
        ->and($fresh->requires_photo)->toBeFalse()
        ->and($fresh->requires_reference)->toBeFalse()
        ->and($fresh->reference_pattern)->toBeNull()
        ->and(AuditLog::query()->where('action', 'payment_channel.update')->count())->toBe(0);
})->with([
    'switched off' => [['is_enabled' => false], 'is_enabled'],
    'a photo' => [['requires_photo' => true], 'requires_photo'],
    'a reference' => [['requires_reference' => true], 'requires_reference'],
    'a pattern' => [['reference_pattern' => '\d{6}'], 'reference_pattern'],
    'a rename that also switches it off' => [['name_en' => 'Till cash', 'name_am' => 'የመሳቢያ ጥሬ ገንዘብ', 'is_enabled' => false], 'is_enabled'],
])->group('module:payments', 'feature');

it('lets the admin rename and move cash, and never remove it', function () {
    $cash = ($this->channel)('cash');

    ($this->api)('PATCH', "/api/v1/payment-channels/{$cash->id}", ['name_en' => 'Till cash', 'name_am' => 'የመሳቢያ ጥሬ ገንዘብ', 'sort' => 20, 'is_enabled' => true, 'requires_photo' => false])
        ->assertOk()->assertJson(['code' => 'cash', 'name_en' => 'Till cash', 'sort' => 20, 'is_cash' => true, 'accepted_now' => true]);

    ($this->api)('DELETE', "/api/v1/payment-channels/{$cash->id}")
        ->assertStatus(409)->assertJsonPath('message_key', 'payments.channel.cash_locked')->assertJsonPath('channel_code', 'cash');
    expect(($this->channel)('cash')->trashed())->toBeFalse()
        ->and(app(Channels::class)->find('cash')?->isCash())->toBeTrue();
})->group('module:payments', 'feature');

it('removes a channel softly: gone from the till, kept for the payments made on it and for the admin', function () {
    $telebirr = ($this->channel)('telebirr');
    $payment = Payment::factory()->create(['branch_id' => $this->branch->id, 'channel_id' => $telebirr->id, 'tendered_amount' => null, 'change_amount' => null]);

    $response = ($this->api)('DELETE', "/api/v1/payment-channels/{$telebirr->id}")->assertOk();

    expect(array_keys($response->json()))->toBe(CHANNEL_KEYS)
        ->and($response->json('code'))->toBe('telebirr')
        ->and($response->json('deleted_at'))->toBeString();
    expect(($this->channel)('telebirr')->trashed())->toBeTrue()
        ->and($payment->fresh()?->channel_id)->toBe($telebirr->id)
        ->and(AuditLog::query()->where('action', 'payment_channel.delete')->where('subject_id', $telebirr->id)->count())->toBe(1)
        ->and(app(Channels::class)->find('telebirr'))->toBeNull();

    $till = ($this->api)('GET', '/api/v1/payment-channels', [], $this->cashier)->assertOk()->json('data');
    expect(array_column($till, 'code'))->not->toContain('telebirr');
    $all = ($this->api)('GET', '/api/v1/payment-channels?all=1')->assertOk()->json('data');
    expect(array_column($all, 'code'))->toBe(['cash', 'cbe_birr', 'cbe_bank', 'awash', 'dashen', 'abyssinia', 'coop', 'telebirr'])
        ->and(end($all))->toMatchArray(['code' => 'telebirr', 'deleted_at' => $response->json('deleted_at')]);

    // removed is removed: no second delete, no edit
    ($this->api)('DELETE', "/api/v1/payment-channels/{$telebirr->id}")->assertNotFound();
    ($this->api)('PATCH', "/api/v1/payment-channels/{$telebirr->id}", ['name_en' => 'Back'])->assertNotFound();
})->group('module:payments', 'feature');

it('shows the removed channels only to the admin', function () {
    ($this->api)('GET', '/api/v1/payment-channels?all=1', [], $this->manager)->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
    ($this->api)('GET', '/api/v1/payment-channels?all=1', [], $this->cashier)->assertForbidden();
    ($this->api)('GET', '/api/v1/payment-channels', [], $this->manager)->assertOk()->assertJsonCount(8, 'data');
    ($this->api)('GET', '/api/v1/payment-channels?all=0', [], $this->cashier)->assertOk()->assertJsonCount(8, 'data');
})->group('module:payments', 'feature');

it('keeps every branch to its own channels', function () {
    $other = Branch::factory()->create();
    $theirs = PaymentChannel::query()->withoutGlobalScopes()->where('branch_id', $other->id)->where('code', 'telebirr')->sole();

    ($this->api)('PATCH', "/api/v1/payment-channels/{$theirs->id}", ['name_en' => 'Mine now'])->assertNotFound();
    ($this->api)('DELETE', "/api/v1/payment-channels/{$theirs->id}")->assertNotFound();
    PaymentChannel::query()->withoutGlobalScopes()->where('branch_id', $other->id)->where('code', 'coop')->delete();

    $all = ($this->api)('GET', '/api/v1/payment-channels?all=1')->assertOk()->json('data');
    expect($all)->toHaveCount(8)
        ->and(PaymentChannel::query()->withoutGlobalScopes()->findOrFail($theirs->id)->name_en)->toBe('Telebirr')
        ->and(PaymentChannel::query()->withoutGlobalScopes()->whereIn('id', array_column($all, 'id'))->pluck('branch_id')->unique()->all())->toBe([$this->branch->id]);
})->group('module:payments', 'feature');

it('keeps Channels::enabled() to its Phase 1 shape, with the new rules added after it', function () {
    $telebirr = ($this->channel)('telebirr');
    ($this->api)('PATCH', "/api/v1/payment-channels/{$telebirr->id}", ['reference_pattern' => '\d{10}', 'reference_hint_en' => 'Ten digits', 'reference_hint_am' => 'አሥር አሃዞች'])->assertOk();

    $enabled = app(Channels::class)->enabled();

    expect($enabled)->toHaveCount(8)
        ->and(array_slice(array_keys($enabled[0]->toArray()), 0, 9))->toBe(['id', 'code', 'name_en', 'name_am', 'is_enabled', 'requires_photo', 'requires_reference', 'sort', 'accepted_now'])
        ->and($enabled[0]->isCash())->toBeTrue()
        ->and($enabled[0]->acceptedNow())->toBeTrue()
        ->and($enabled[1]->code)->toBe('telebirr')
        ->and($enabled[1]->isCash())->toBeFalse()
        ->and($enabled[1]->acceptedNow())->toBeFalse()
        ->and($enabled[1]->referencePattern)->toBe('\d{10}')
        ->and($enabled[1]->referenceHintEn)->toBe('Ten digits')
        ->and($enabled[1]->referenceHintAm)->toBe('አሥር አሃዞች')
        ->and($enabled[1]->photoHintEn)->toBeNull()
        ->and($enabled[1]->toArray())->toMatchArray(['code' => 'telebirr', 'reference_pattern' => '\d{10}', 'is_cash' => false]);
})->group('module:payments', 'feature');

it('leaves the till as it was: cash still taken after the admin\'s changes, a photo channel still refused until step 4.3b', function () {
    $f = OrderFloor::open($this);
    $m = $f->menu;
    $cash = PaymentChannel::query()->where('code', 'cash')->sole();
    ($this->api)('PATCH', "/api/v1/payment-channels/{$cash->id}", ['name_en' => 'Till cash', 'name_am' => 'የመሳቢያ ጥሬ ገንዘብ'], $f->admin)->assertOk();
    ($this->api)('PUT', '/api/v1/payment-channels/order', ['ids' => PaymentChannel::query()->orderByDesc('sort')->pluck('id')->all()], $f->admin)->assertOk();
    ($this->api)('POST', '/api/v1/payment-channels', ($this->wallet)(), $f->admin)->assertCreated();
    $order = $f->send([$m->line($m->water)]);

    ($this->api)('POST', "/api/v1/orders/{$order['id']}/payments", ['client_uuid' => (string) Str::uuid(), 'channel_code' => 'amole', 'amount' => '30.00', 'reference_no' => 'AB12CD34EF'], $f->cashier)
        ->assertStatus(422)->assertJsonPath('message_key', 'payments.channel.photo_required');
    ($this->api)('POST', "/api/v1/orders/{$order['id']}/payments", ['client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => '30.00', 'tendered_amount' => '50.00'], $f->cashier)
        ->assertCreated()->assertJsonPath('payment.change_amount', '20.00');
})->group('module:payments', 'feature');

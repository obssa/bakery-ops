<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Contracts\Channels;
use App\Modules\Payments\Contracts\ChannelView;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Models\Branch;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * PUT /payment-channels/order (phase-4 plan section 4.2, step 4.3a; channels.manage): {ids} names every live channel of the branch
 * (switched-off ones included, removed ones not) exactly once, and the sort becomes 1..n in that order, in one AuditedAction
 * (payment_channel.reorder). Anything else is 422 payments.channel.order_incomplete with what is missing and what does not belong,
 * and nothing moves. The till, Channels::enabled() and the admin list all read the new order.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    $this->put = function (array $ids, ?User $as = null): TestResponse {
        loginAs($as ?? $this->admin);

        return $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->putJson('/api/v1/payment-channels/order', ['ids' => $ids]);
    };
    $this->id = fn (string $code): int => PaymentChannel::query()->withTrashed()->where('code', $code)->sole()->id;
    $this->sorts = fn (): array => PaymentChannel::query()->withTrashed()->orderBy('id')->pluck('sort', 'code')->all();
    $this->live = fn (): array => PaymentChannel::query()->orderBy('sort')->orderBy('id')->pluck('id')->all();
});

it('renumbers the channels 1..n in the order given, switched-off ones included', function () {
    PaymentChannel::query()->where('code', 'awash')->update(['is_enabled' => false]);
    $order = ['coop', 'cash', 'awash', 'telebirr', 'abyssinia', 'cbe_bank', 'dashen', 'cbe_birr'];

    $response = ($this->put)(array_map($this->id, $order))->assertOk();

    expect(array_column($response->json('data'), 'code'))->toBe($order)
        ->and(array_column($response->json('data'), 'sort'))->toBe([1, 2, 3, 4, 5, 6, 7, 8])
        ->and($response->json('data.2'))->toMatchArray(['code' => 'awash', 'is_enabled' => false]);
    loginAs(makeUser($this->branch, 'cashier'));
    expect(array_column($this->getJson('/api/v1/payment-channels')->assertOk()->json('data'), 'code'))->toBe($order)
        ->and(array_map(fn (ChannelView $c): string => $c->code, app(Channels::class)->enabled()))->toBe(array_values(array_diff($order, ['awash'])));
})->group('module:payments', 'feature');

it('refuses a list that is not every live channel exactly once, and moves nothing', function (Closure $case) {
    PaymentChannel::query()->where('code', 'coop')->delete();
    $before = ($this->sorts)();
    /** @var array{ids: list<int>, missing: list<int>, unexpected: list<int>} $c */
    $c = $case->call($this);

    $response = ($this->put)($c['ids'])->assertStatus(422);

    // missing: the live channels the list leaves out; unexpected: what it names that is not a live channel of the branch, or names twice
    $response->assertJsonPath('message_key', 'payments.channel.order_incomplete')->assertJsonPath('field', 'ids');
    expect($response->json('missing'))->toBe($c['missing'])
        ->and($response->json('unexpected'))->toBe($c['unexpected'])
        ->and($response->json('message_am'))->toMatch('/\p{Ethiopic}/u')
        ->and(($this->sorts)())->toBe($before)
        ->and(AuditLog::query()->where('action', 'payment_channel.reorder')->count())->toBe(0);
})->with([
    'one missing' => [fn (): array => ['ids' => array_slice(($this->live)(), 1), 'missing' => [($this->id)('cash')], 'unexpected' => []]],
    'one twice' => [fn (): array => ['ids' => [...($this->live)(), ($this->id)('cash')], 'missing' => [], 'unexpected' => [($this->id)('cash')]]],
    'an unknown id' => [fn (): array => ['ids' => [...($this->live)(), 999999], 'missing' => [], 'unexpected' => [999999]]],
    'a removed channel in place of a live one' => [fn (): array => [
        'ids' => [...array_slice(($this->live)(), 0, 6), ($this->id)('coop')], 'missing' => [($this->id)('abyssinia')], 'unexpected' => [($this->id)('coop')],
    ]],
    'a channel of another branch' => [function (): array {
        $other = Branch::factory()->create();
        $theirs = (int) PaymentChannel::query()->withoutGlobalScopes()->where('branch_id', $other->id)->where('code', 'cash')->value('id');

        return ['ids' => [...($this->live)(), $theirs], 'missing' => [], 'unexpected' => [$theirs]];
    }],
])->group('module:payments', 'feature');

it('validates the list', function (mixed $ids) {
    loginAs($this->admin);

    $this->withHeaders(idempotencyKey())->putJson('/api/v1/payment-channels/order', $ids === '__none__' ? [] : ['ids' => $ids])
        ->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
})->with([
    'no ids' => ['__none__'],
    'an empty list' => [[]],
    'not a list' => ['1,2,3'],
    'a name instead of an id' => [['cash', 'telebirr']],
])->group('module:payments', 'feature');

it('puts a channel created after a reorder last, and leaves the removed ones out of the numbers', function () {
    PaymentChannel::query()->where('code', 'coop')->delete();
    ($this->put)(array_reverse(($this->live)()))->assertOk();

    loginAs($this->admin);
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/payment-channels', [
        'client_uuid' => (string) Str::uuid(), 'code' => 'amole', 'name_en' => 'Amole', 'name_am' => 'አሞሌ', 'requires_photo' => true, 'requires_reference' => true,
    ])->assertCreated()->assertJsonPath('sort', 9);

    expect(PaymentChannel::query()->orderBy('sort')->pluck('code')->all())->toBe(['abyssinia', 'dashen', 'awash', 'cbe_bank', 'cbe_birr', 'telebirr', 'cash', 'amole']);
})->group('module:payments', 'feature');

it('audits the reorder with the order before and after', function () {
    $before = ($this->live)();
    $after = array_reverse($before);

    ($this->put)($after)->assertOk();

    $audit = AuditLog::query()->where('action', 'payment_channel.reorder')->sole();
    expect($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->subject_type)->toBe('none')
        ->and($audit->context['before'] ?? null)->toBe($before)
        ->and($audit->context['after'] ?? null)->toBe($after);
})->group('module:payments', 'feature');

it('is the admin\'s: a manager gets 403 and nothing moves', function () {
    $before = ($this->sorts)();

    ($this->put)(array_reverse(($this->live)()), makeUser($this->branch, 'manager'))->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');

    expect(($this->sorts)())->toBe($before);
})->group('module:payments', 'feature');

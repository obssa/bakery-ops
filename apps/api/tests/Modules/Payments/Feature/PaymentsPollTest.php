<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Contracts\Payments;
use App\Modules\Payments\Models\Payment;
use App\Shared\Live\OverlapCursor;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrderFloor;

pest()->group('module:payments', 'feature');

/*
 * GET /payments is polled by the admin's payments screen (step 4.5c; ADR 0009): a conditional read (ConditionalPoll), whose ETag comes from
 * Payments::fingerprint() (the branch's payment count, highest id, latest updated_at, and the current second while the newest change is
 * younger than the 5 s overlap window, as Stock::fingerprint does: ids become visible in commit order, not in id order) plus the query and
 * the viewer's scope. Every test that expects a 304 first travels past the settling window.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $this->list = function (string $query = '', ?string $etag = null, ?User $as = null): TestResponse {
        loginAs($as ?? $this->floor->manager);

        return $this->withHeaders($etag === null ? [] : ['If-None-Match' => $etag])->getJson('/api/v1/payments'.($query === '' ? '' : '?'.$query));
    };
    $this->pay = function (): int {
        $f = $this->floor;
        $order = $f->send([$f->menu->line($f->menu->water)]);
        loginAs($f->cashier);

        return (int) $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/orders/{$order['id']}/payments", ['client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => '30.00'])
            ->assertCreated()->json('payment.id');
    };
    $this->settle = fn () => $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds();
    $this->tag = fn (TestResponse $r): string => (string) $r->headers->get('ETag');
});

it('stamps an ETag and answers 304, weakly compared, once the newest payment is older than the settling window', function () {
    ($this->pay)();
    ($this->settle)();

    $first = ($this->list)()->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
    $etag = ($this->tag)($first);
    expect($etag)->toStartWith('"payments-');

    $again = ($this->list)('', $etag);
    $again->assertStatus(304);
    expect($again->getContent())->toBe('')->and(($this->tag)($again))->toBe($etag);
    ($this->list)('', 'W/'.$etag)->assertStatus(304);
});

it('moves the tag when a payment is taken or changed, and on every second while the newest change is younger than the window', function () {
    ($this->pay)();
    ($this->settle)();
    $before = ($this->tag)(($this->list)()->assertOk());

    $id = ($this->pay)();
    $taken = ($this->list)('', $before)->assertOk();
    $young = ($this->tag)($taken);
    expect($young)->not->toBe($before)->and($taken->json('data.0.id'))->toBe($id);

    // inside the window the tag moves every second, so a row that commits late below the highest id is still fetched
    $this->travel(1)->seconds();
    $next = ($this->tag)(($this->list)('', $young)->assertOk());
    expect($next)->not->toBe($young);

    ($this->settle)();
    $settled = ($this->tag)(($this->list)('', $next)->assertOk());
    ($this->list)('', $settled)->assertStatus(304);

    // a change of a payment (a status) moves it too, even when nothing is added
    Payment::query()->whereKey($id)->update(['status' => 'disputed']);
    $changed = ($this->list)('', $settled)->assertOk();
    expect(($this->tag)($changed))->not->toBe($settled)->and($changed->json('data.0.status'))->toBe('disputed');
});

it('keeps one tag per query and per viewer', function () {
    ($this->pay)();
    ($this->settle)();

    $all = ($this->tag)(($this->list)()->assertOk());
    $recorded = ($this->tag)(($this->list)('status=recorded')->assertOk());
    $paged = ($this->tag)(($this->list)('limit=1')->assertOk());
    $cashier = ($this->tag)(($this->list)('', null, $this->floor->cashier)->assertOk());

    expect(array_unique([$all, $recorded, $paged, $cashier]))->toHaveCount(4);
    ($this->list)('status=recorded', $all)->assertOk();
    ($this->list)('', $all, $this->floor->cashier)->assertOk();
});

it('renews the tag before the signed receipt URLs it carries expire', function () {
    ($this->pay)();
    ($this->settle)();
    $tag = ($this->tag)(($this->list)()->assertOk());

    $this->travel(intdiv((int) config('files.url_ttl_seconds'), 2) + 1)->seconds();

    ($this->list)('', $tag)->assertOk();
});

it('builds the tag from Payments::fingerprint(): count, highest id, latest change and the settling second', function () {
    $this->freezeTime();
    $empty = app(Payments::class)->fingerprint();
    expect($empty)->toBe(['count' => 0, 'max_id' => null, 'max_updated_at' => null, 'settling' => null]);

    $id = ($this->pay)();
    $young = app(Payments::class)->fingerprint();
    expect(array_keys($young))->toBe(['count', 'max_id', 'max_updated_at', 'settling'])
        ->and($young['count'])->toBe(1)
        ->and($young['max_id'])->toBe($id)
        ->and($young['max_updated_at'])->toBeString()
        ->and($young['settling'])->toBe(now()->getTimestamp());

    ($this->settle)();
    expect(app(Payments::class)->fingerprint()['settling'])->toBeNull();
});

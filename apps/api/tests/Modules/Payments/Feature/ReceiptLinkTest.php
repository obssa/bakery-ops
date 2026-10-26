<?php

declare(strict_types=1);

use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;
use Tests\Support\Receipts;

/*
 * POST /orders/{order}/receipt-link (phase-4 plan section 4.5, step 4.3e; payments.take: admin, manager, cashier; Idempotency-Key):
 * {ttl_hours?: 1 to 168, 72 by default, locale?: en|am|both} answers {url, expires_at}, a signed link to GET /receipts/{order}, the
 * public receipt a customer opens without a bearer token. The signature covers the order, its branch, the locale and the expiry, so a
 * changed one, or a link past its expiry, is 403; the PDF it serves is the one GET /orders/{order}/receipt.pdf answers, never cached,
 * and names nobody. One audit row receipt.link per link made, without the URL (a link is a credential for its lifetime). An order that
 * is not paid in full gets no link (409 payments.receipt.unpaid).
 */

pest()->group('module:payments', 'feature');

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->floor = OrderFloor::open($this);
    $this->tea = Receipts::tea($this->floor);
    $f = $this->floor;
    $this->order = $f->send([$f->menu->line($this->tea, '2')], $f->waiter);
    Receipts::pay($this, $f, $this->order['id'], 'cash', '25.00')->assertCreated();
    $this->link = function (array $body = [], ?int $orderId = null, ?string $key = null) {
        loginAs($this->floor->cashier);

        return $this->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])
            ->postJson('/api/v1/orders/'.($orderId ?? $this->order['id']).'/receipt-link', $body);
    };
    // a link is opened by a customer: no bearer token, no session
    $this->open = function (string $url) {
        app('auth')->forgetGuards();

        return $this->get($url, ['Accept' => 'application/json']);
    };
});

it('makes a signed link that opens the receipt without a bearer token, valid for 72 hours by default', function () {
    $this->freezeTime();

    $response = ($this->link)();

    $response->assertOk();
    $url = (string) $response->json('url');
    expect($url)->toContain('/api/v1/receipts/'.$this->order['id'])->toContain('signature=')->toContain('expires=')
        ->and($response->json('expires_at'))->toBe(CarbonImmutable::now()->addHours(72)->toIso8601String());

    $pdf = ($this->open)($url);
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($pdf->headers->get('Referrer-Policy'))->toBe('no-referrer');
    $text = Receipts::text((string) $pdf->getContent());
    expect($text)->toContain('25.00')->toContain((string) $this->order['order_no']);
    foreach (['Admin', 'Meron', 'Selam', 'Abebe'] as $name) {
        expect($text)->not->toContain($name);
    }
});

it('takes a lifetime of 1 to 168 hours and a locale', function () {
    $this->freezeTime();

    $week = ($this->link)(['ttl_hours' => 168, 'locale' => 'en'])->assertOk();
    expect($week->json('expires_at'))->toBe(CarbonImmutable::now()->addHours(168)->toIso8601String());
    expect(preg_match('/\p{Ethiopic}/u', Receipts::text((string) ($this->open)((string) $week->json('url'))->assertOk()->getContent())))->toBe(0);

    foreach ([0, 169, 'soon'] as $ttl) {
        ($this->link)(['ttl_hours' => $ttl])->assertStatus(422)->assertJsonStructure(['errors' => ['ttl_hours']]);
    }
    ($this->link)(['locale' => 'fr'])->assertStatus(422)->assertJsonStructure(['errors' => ['locale']]);
});

it('stops opening once it expires', function () {
    $url = (string) ($this->link)(['ttl_hours' => 1])->assertOk()->json('url');

    $this->travel(59)->minutes();
    ($this->open)($url)->assertOk();
    $this->travel(2)->minutes();
    ($this->open)($url)->assertForbidden()->assertJsonPath('message_key', 'payments.receipt.link_invalid');
});

it('refuses a link whose order, branch, locale, expiry or signature was changed', function () {
    $url = (string) ($this->link)()->assertOk()->json('url');
    $other = $this->floor->send([$this->floor->menu->line($this->tea)], $this->floor->waiter);
    Receipts::pay($this, $this->floor, $other['id'], 'cash', '12.50')->assertCreated();
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $tampered = [
        str_replace('/receipts/'.$this->order['id'].'?', '/receipts/'.$other['id'].'?', $url),
        str_replace('branch='.$query['branch'], 'branch='.Branch::factory()->create()->id, $url),
        $url.'&locale=am',
        str_replace('expires='.$query['expires'], 'expires='.((int) $query['expires'] + 3600), $url),
        str_replace('signature='.$query['signature'], 'signature='.str_repeat('0', 64), $url),
        strtok($url, '?'),
    ];
    foreach ($tampered as $bad) {
        ($this->open)($bad)->assertForbidden()->assertJsonPath('message_key', 'payments.receipt.link_invalid')->assertJsonStructure(['message_en', 'message_am']);
    }
});

it('replays the same key with the same link and audits each link once, without the URL', function () {
    $key = (string) Str::uuid();

    $first = ($this->link)([], null, $key)->assertOk();
    $second = ($this->link)([], null, $key);

    $second->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('url', $first->json('url'));
    $audit = DB::table('audit_log')->where('action', 'receipt.link')->sole();
    expect($audit->actor_id)->toBe($this->floor->cashier->id)
        ->and(json_encode($audit))->not->toContain('signature')->not->toContain((string) $first->json('url'))
        ->and(json_decode((string) $audit->after, true))->toMatchArray(['order_id' => $this->order['id'], 'ttl_hours' => 72]);
});

it('makes no link for an order that is not paid in full, and finds no other branch\'s order', function () {
    $f = $this->floor;
    $open = $f->send([$f->menu->line($this->tea)], $f->waiter);

    ($this->link)([], $open['id'])->assertStatus(409)->assertJsonPath('message_key', 'payments.receipt.unpaid');
    ($this->link)([], 987654)->assertNotFound();
    expect(DB::table('audit_log')->where('action', 'receipt.link')->count())->toBe(0);
});

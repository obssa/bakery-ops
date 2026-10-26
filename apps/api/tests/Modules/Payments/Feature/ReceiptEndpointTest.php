<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OrderFloor;
use Tests\Support\Receipts;

/*
 * GET /orders/{order}/receipt.pdf (phase-4 plan section 4.5, step 4.3e; payments.view: admin, manager, cashier): the receipt of an order
 * that is paid in full (settled, paid or closed) as application/pdf, inline, never cached (Cache-Control: private, no-store). ?locale=
 * en|am|both, by default both when the receipt settings show Amharic and English otherwise. An order that is not paid in full is 409
 * payments.receipt.unpaid {status} unless ?proforma=1 asks for a pro-forma (its header says so); a cancelled or voided order has no
 * receipt at all. Another branch's order is 404.
 */

pest()->group('module:payments', 'feature');

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->floor = OrderFloor::open($this);
    $this->tea = Receipts::tea($this->floor);
    $this->get = function (int $orderId, string $query = '', ?string $role = null) {
        loginAs($role === null ? $this->floor->cashier : makeUser($this->floor->branch, $role));

        return $this->get("/api/v1/orders/{$orderId}/receipt.pdf{$query}", ['Accept' => 'application/json']);
    };
    $this->paid = function (): array {
        $f = $this->floor;
        $order = $f->send([$f->menu->line($this->tea, '2')], $f->waiter);
        Receipts::pay($this, $f, $order['id'], 'cash', '25.00')->assertCreated();

        return $order;
    };
});

it('answers the PDF of a paid order, inline and never cached', function (string $role) {
    $order = ($this->paid)();

    $response = ($this->get)($order['id'], '', $role);

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')->toContain('.pdf')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-')
        ->and(Receipts::text((string) $response->getContent()))->toContain('25.00')->toContain((string) $order['order_no']);
})->with(['admin', 'manager', 'cashier']);

it('prints both languages by default, English only when the settings hide Amharic, and what ?locale asks', function () {
    $order = ($this->paid)();

    $both = Receipts::text((string) ($this->get)($order['id'])->assertOk()->getContent());
    expect($both)->toContain('Total')->toContain('ጠቅላላ');

    loginAs($this->floor->admin);
    $this->withHeaders(idempotencyKey())->putJson('/api/v1/receipt-settings', [
        'business_name_en' => 'Bole Bakery', 'business_name_am' => 'ቦሌ ዳቦ ቤት', 'show_amharic' => false, 'ethiopian_dates' => false,
    ])->assertOk();
    $en = Receipts::text((string) ($this->get)($order['id'])->assertOk()->getContent());
    $am = Receipts::text((string) ($this->get)($order['id'], '?locale=am')->assertOk()->getContent());

    expect(preg_match('/\p{Ethiopic}/u', $en))->toBe(0)->and($en)->toContain('Bole Bakery')
        ->and($am)->toContain('ቦሌ ዳቦ ቤት')->toContain('ጠቅላላ');
    ($this->get)($order['id'], '?locale=fr')->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
});

it('refuses the receipt of an order that is not paid in full, unless a pro-forma is asked for', function () {
    $f = $this->floor;
    $order = $f->send([$f->menu->line($this->tea, '2')], $f->waiter);
    Receipts::pay($this, $f, $order['id'], 'cash', '10.00')->assertCreated();

    ($this->get)($order['id'])->assertStatus(409)
        ->assertJsonPath('message_key', 'payments.receipt.unpaid')->assertJsonPath('status', Order::query()->findOrFail($order['id'])->status->value)
        ->assertJsonStructure(['message_en', 'message_am']);
    $proforma = ($this->get)($order['id'], '?proforma=1')->assertOk();

    expect(Receipts::text((string) $proforma->getContent()))->toContain('PRO-FORMA')->toContain('15.00'); // the balance still due
});

it('has no receipt for a cancelled or a voided order, pro-forma or not', function (string $status) {
    $f = $this->floor;
    $order = $f->send([$f->menu->line($this->tea)], $f->waiter);
    DB::table('orders')->where('id', $order['id'])->update(['status' => $status]);

    ($this->get)($order['id'])->assertStatus(409)->assertJsonPath('message_key', 'payments.receipt.unpaid')->assertJsonPath('status', $status);
    ($this->get)($order['id'], '?proforma=1')->assertStatus(409)->assertJsonPath('status', $status);
})->with(['cancelled', 'voided']);

it('does not find another branch\'s order, or none', function () {
    $theirs = Order::factory()->sent(1)->create(['branch_id' => Branch::factory()->create()->id]);

    ($this->get)($theirs->id)->assertNotFound();
    ($this->get)(987654)->assertNotFound();
});

it('is for the payment readers only', function (string $role) {
    $order = ($this->paid)();

    ($this->get)($order['id'], '', $role)->assertForbidden();
})->with(['waiter', 'chef', 'barista']);

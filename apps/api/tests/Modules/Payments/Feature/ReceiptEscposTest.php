<?php

declare(strict_types=1);

use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Support\EscPosEmulator;
use Tests\Support\PrintFixture;

/*
 * GET /orders/{order}/receipt.escpos (step 5.3a, phase-5 plan 4.3 and 10.2 D11): the receipt's ESC/POS bytes for a till PC that prints on
 * its own printer while the internet or the bridge is down (print.view: admin, manager, cashier). ?width=576|384, else the receipt settings'
 * paper width. Without FreeType (or with print.raster off) the bytes are the printer's own Latin text, no Amharic.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->cashier = makeUser($this->branch, 'cashier');
    $this->order = PrintFixture::paidOrder($this->cashier);
});

it('answers the receipt as ESC/POS bytes at the width asked, never cached', function () {
    loginAs($this->cashier);
    $response = $this->get("/api/v1/orders/{$this->order->id}/receipt.escpos?width=384")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    $printer = EscPosEmulator::parse((string) $response->getContent());
    expect($printer->bitmaps())->toHaveCount(1)->and($printer->bitmaps()[0]->width)->toBe(384);
})->group('module:payments', 'feature');

it('draws at the receipt settings\' paper width by default: 80 mm, or 58 mm when set', function () {
    loginAs($this->cashier);
    expect(EscPosEmulator::parse((string) $this->get("/api/v1/orders/{$this->order->id}/receipt.escpos")->assertOk()->getContent())->bitmaps()[0]->width)->toBe(576);

    DB::table('receipt_settings')->insert([
        'branch_id' => $this->branch->id, 'business_name_en' => 'Demo', 'business_name_am' => 'ማሳያ', 'show_amharic' => true, 'ethiopian_dates' => false,
        'paper_width_mm' => 58, 'created_by_user_id' => $this->cashier->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(EscPosEmulator::parse((string) $this->get("/api/v1/orders/{$this->order->id}/receipt.escpos")->assertOk()->getContent())->bitmaps()[0]->width)->toBe(384);
})->group('module:payments', 'feature');

it('falls back to the printer\'s Latin text, with no Amharic, when the raster is off', function () {
    config(['print.raster' => false]);
    loginAs($this->cashier);

    $printer = EscPosEmulator::parse((string) $this->get("/api/v1/orders/{$this->order->id}/receipt.escpos?width=576")->assertOk()->getContent());
    $text = implode("\n", $printer->lines());

    expect($printer->bitmaps())->toBe([])
        ->and($text)->not->toContain(' / ')
        ->and($text)->toContain(trans('receipts.non_fiscal', [], 'en'))
        ->and(max(array_map('strlen', $printer->lines())))->toBeLessThanOrEqual(48);
    DB::table('receipt_settings')->insert([
        'branch_id' => $this->branch->id, 'business_name_en' => 'Demo', 'business_name_am' => 'ማሳያ', 'tin' => '0012345678', 'show_amharic' => true,
        'ethiopian_dates' => false, 'paper_width_mm' => 58, 'created_by_user_id' => $this->cashier->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $narrow = EscPosEmulator::parse((string) $this->get("/api/v1/orders/{$this->order->id}/receipt.escpos")->assertOk()->getContent());
    expect(max(array_map('strlen', $narrow->lines())))->toBeLessThanOrEqual(32)
        ->and(implode("\n", $narrow->lines()))->toContain('TIN: 0012345678')->not->toContain('?');
})->group('module:payments', 'feature');

it('refuses an order not paid in full, another branch\'s order, and a width a printer does not take', function () {
    loginAs($this->cashier);
    $this->get("/api/v1/orders/{$this->order->id}/receipt.escpos?width=500", ['Accept' => 'application/json'])->assertStatus(422);

    DB::table('orders')->where('id', $this->order->id)->update(['settled_at' => null, 'paid_total' => '0.00']);
    $this->getJson("/api/v1/orders/{$this->order->id}/receipt.escpos")->assertStatus(409)->assertJsonPath('message_key', 'payments.print.not_paid');

    $theirs = PrintFixture::paidOrder(makeUser(Branch::factory()->create(), 'cashier'), 5);
    $this->getJson("/api/v1/orders/{$theirs->id}/receipt.escpos")->assertNotFound();
})->group('module:payments', 'feature');

it('lets only the till roles read the bytes', function (string $role, bool $allowed) {
    loginAs(makeUser($this->branch, $role));

    $response = $this->getJson("/api/v1/orders/{$this->order->id}/receipt.escpos");
    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    ['admin', true], ['manager', true], ['cashier', true],
    ['waiter', false], ['chef', false], ['barista', false], ['cake_baker', false], ['bread_baker', false],
])->group('module:payments', 'feature');

<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Support\Receipts\Receipt;
use App\Modules\Payments\Support\Receipts\ReceiptBuilder;
use App\Modules\Payments\Support\Receipts\ReceiptRenderer;
use App\Shared\Time\EthiopianDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;
use Tests\Support\Receipts;

/*
 * The receipt PDF (phase-4 plan section 4.5, step 4.3e). ReceiptBuilder reads the order through Orders::forReceipt and Payments' own rows
 * (payments that are not voided, payment_refunds, the channels, receipt_settings); ReceiptRenderer prints it with dompdf, DejaVu Sans for
 * Latin text and the bundled Noto Sans Ethiopic for Amharic (embedded as a subset), at most two pages, Western digits throughout. A
 * reference prints its last 4 characters only, and no person is named: not the waiter, the cashier, the approver or the cook. The PDF is
 * read back with an independent parser (smalot/pdfparser): its text and its page count.
 */

pest()->group('module:payments', 'feature');

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->floor = OrderFloor::open($this);
    $this->tea = Receipts::tea($this->floor);
    $this->staff = ['Admin', 'Meron', 'Selam', 'Abebe', 'Chaltu', 'Dawit', 'Elias'];
    // a receipt as the renderer takes it, for the cases about layout alone
    $this->receipt = function (array $change = []): Receipt {
        $args = $change + [
            'business' => ['name_en' => 'Bole Bakery', 'name_am' => 'ቦሌ ዳቦ ቤት', 'address_en' => 'Bole Road', 'address_am' => 'ቦሌ መንገድ',
                'phone' => '+251 11 000 0101', 'tin' => '0012345678', 'footer_en' => 'Thank you', 'footer_am' => 'እናመሰግናለን'],
            'locale' => 'both',
            'proforma' => false,
            'orderNo' => 4711,
            'businessDate' => '2026-10-26',
            'orderType' => 'takeaway',
            'tableCode' => null,
            'issuedAt' => CarbonImmutable::parse('2026-10-26 09:15:00', 'Africa/Addis_Ababa'),
            'ethiopianDate' => null,
            'lines' => [],
            'money' => ['subtotal' => '0.00', 'discount' => '0.00', 'tax' => '0.00', 'total' => '0.00', 'paid' => '0.00', 'balance' => '0.00',
                'change' => '0.00', 'refunded' => '0.00', 'net_paid' => '0.00', 'currency' => 'ETB'],
            'payments' => [],
            'refunds' => [],
        ];

        return new Receipt(...$args);
    };
    $this->lines = fn (int $n): array => array_map(fn (int $i): array => [
        'name_en' => "Line item number {$i}", 'name_am' => "የሙከራ እቃ ቁጥር {$i}", 'qty' => '1.0000', 'unit_price' => '10.00', 'line_total' => '10.00',
        'modifiers' => $i % 5 === 0 ? [['name_en' => 'Extra butter', 'name_am' => 'ተጨማሪ ቅቤ', 'price_delta' => '0.00']] : [],
    ], range(1, $n));
});

it('prints the order number, the total as 12.50, the Amharic channel name and the reference tail, and names nobody', function () {
    $f = $this->floor;
    $order = $f->send([$f->menu->line($this->tea)], $f->waiter);
    Receipts::pay($this, $f, $order['id'], 'telebirr', '5.00', ['reference_no' => 'FT26TX009876'])->assertCreated();
    Receipts::pay($this, $f, $order['id'], 'cash', '7.50', ['tendered_amount' => '10.00'])->assertCreated();

    $pdf = app(ReceiptRenderer::class)->render(app(ReceiptBuilder::class)->build($order['id']));

    expect(substr($pdf, 0, 5))->toBe('%PDF-');
    $text = Receipts::text($pdf);
    expect($text)->toContain((string) $order['order_no'])
        ->toContain('12.50')
        ->toContain('ቴሌብር')
        ->toContain('Telebirr')
        ->toContain('9876')
        ->not->toContain('FT26TX00')
        ->toContain('7.50')
        ->toContain('2.50', 'the change of the cash payment')
        ->toContain('Black tea')
        ->toContain('ሻይ');
    foreach ($this->staff as $name) {
        expect($text)->not->toContain($name);
    }
    expect(preg_match('/[\x{1369}-\x{137C}]/u', $text))->toBe(0, 'Western digits only: no Ethiopic numerals');
});

it('leaves a voided payment off the receipt', function () {
    $f = $this->floor;
    $order = $f->send([$f->menu->line($this->tea, '2')], $f->waiter);
    $voided = (int) Receipts::pay($this, $f, $order['id'], 'awash', '25.00', ['reference_no' => 'AW77665544'])->assertCreated()->json('payment.id');
    withActor($f->admin->id);
    app(ShortCodes::class)->set($f->manager, '246802');
    loginAs($f->cashier);
    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
        ->postJson("/api/v1/payments/{$voided}/void", ['reason' => 'Wrong bank', 'pin' => '246802'])->assertOk();
    Receipts::pay($this, $f, $order['id'], 'cash', '25.00')->assertCreated();

    $text = Receipts::text(app(ReceiptRenderer::class)->render(app(ReceiptBuilder::class)->build($order['id'])));

    expect($text)->toContain('25.00')->not->toContain('አዋሽ')->not->toContain('Awash')->not->toContain('5544');
});

it('prints the refunds and what was paid net of them', function () {
    $f = $this->floor;
    $order = $f->send([$f->menu->line($this->tea, '4')], $f->waiter);
    $paymentId = (int) Receipts::pay($this, $f, $order['id'], 'cash', '50.00')->assertCreated()->json('payment.id');
    Receipts::refund(Payment::query()->findOrFail($paymentId), '12.50', now(), $f->manager->id);

    $receipt = app(ReceiptBuilder::class)->build($order['id']);
    $text = Receipts::text(app(ReceiptRenderer::class)->render($receipt));

    expect($receipt->money['refunded'])->toBe('12.50')
        ->and($receipt->money['net_paid'])->toBe('37.50')
        ->and($receipt->refunds)->toHaveCount(1)
        ->and($text)->toContain('Refund')->toContain('12.50')->toContain('37.50');
});

it('keeps a 60-line order to two pages, every line printed, in both languages', function () {
    $pdf = app(ReceiptRenderer::class)->render(($this->receipt)(['lines' => ($this->lines)(60)]));

    expect(Receipts::pages($pdf))->toBeLessThanOrEqual(2);
    $text = Receipts::text($pdf);
    foreach ([1, 30, 59, 60] as $i) {
        expect($text)->toContain("Line item number {$i}");
    }
    expect($text)->toContain('የሙከራ እቃ ቁጥር');
});

it('keeps a very long order to two pages too, folding the last lines into one with their count', function () {
    $pdf = app(ReceiptRenderer::class)->render(($this->receipt)(['lines' => ($this->lines)(240)]));

    expect(Receipts::pages($pdf))->toBeLessThanOrEqual(2);
    $text = Receipts::text($pdf);
    expect($text)->toContain('Line item number 1')->not->toContain('Line item number 240')
        ->and(preg_match('/(\d+) more items/', $text, $m))->toBe(1)
        ->and((int) $m[1])->toBeGreaterThan(0)->toBeLessThan(240);
});

it('prints English only, or Amharic only, when asked', function () {
    $lines = ($this->lines)(2);

    $en = Receipts::text(app(ReceiptRenderer::class)->render(($this->receipt)(['locale' => 'en', 'lines' => $lines])));
    $am = Receipts::text(app(ReceiptRenderer::class)->render(($this->receipt)(['locale' => 'am', 'lines' => $lines])));

    expect(preg_match('/\p{Ethiopic}/u', $en))->toBe(0)
        ->and($en)->toContain('Total')->toContain('Bole Bakery')->toContain('Line item number 2')
        ->and($am)->toContain('ጠቅላላ')->toContain('ቦሌ ዳቦ ቤት')->toContain('የሙከራ እቃ ቁጥር 2')->not->toContain('Line item number');
});

it('prints the Ethiopian date when the settings ask for it, and the TIN', function () {
    $issued = CarbonImmutable::parse('2026-10-26 09:15:00', 'Africa/Addis_Ababa');

    $text = Receipts::text(app(ReceiptRenderer::class)->render(($this->receipt)(['issuedAt' => $issued, 'ethiopianDate' => EthiopianDate::of($issued)])));

    expect($text)->toContain('Tikimt 16, 2019')->toContain('ጥቅምት 16')->toContain('0012345678')->toContain('2026-10-26');
});

it('says so at the top of a pro-forma', function () {
    $text = Receipts::text(app(ReceiptRenderer::class)->render(($this->receipt)(['proforma' => true, 'lines' => ($this->lines)(1)])));

    expect($text)->toContain('PRO-FORMA')->toContain('not a receipt');
});

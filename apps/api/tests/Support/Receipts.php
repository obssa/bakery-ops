<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Services\PriceHistory;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Models\Payment;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

/**
 * The receipt, reconciliation and export tests of step 4.3e. A PDF is read back with an independent parser (smalot/pdfparser, a dev
 * dependency): its text and its page count. tea() adds a 12.50 product to the OrdersMenu of a floor (the total the receipt test looks for);
 * pay() takes a payment through POST /orders/{order}/payments as the cashier, the way the till does (a digital channel with a photo
 * uploaded through POST /files and a reference); refund() writes a payment_refunds row the way step 4.3d will (nothing writes the table
 * before it: a raw row stands in, pointing at a raw order_refunds row of its own since step 4.1b made order_refund_id a foreign key).
 */
final class Receipts
{
    public static function text(string $pdf): string
    {
        return (new Parser)->parseContent($pdf)->getText();
    }

    public static function pages(string $pdf): int
    {
        return count((new Parser)->parseContent($pdf)->getPages());
    }

    /** A bought-in display-case product at 12.50 with 200 in stock. */
    public static function tea(OrderFloor $floor): Item
    {
        $tea = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $floor->branch->id, 'name_en' => 'Black tea', 'name_am' => 'ሻይ']);
        app(PriceHistory::class)->open($tea, Money::of('12.50'), CarbonImmutable::now()->subDay()->startOfSecond());
        stockIn($tea, $floor->menu->display, '200');

        return $tea;
    }

    /**
     * POST /orders/{order}/payments as $as (the floor's cashier by default). A channel other than cash gets a fresh photo and the reference given.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function pay(TestCase $test, OrderFloor $floor, int $orderId, string $channel, string $amount, array $extra = [], ?User $as = null): TestResponse
    {
        $as ??= $floor->cashier;
        $body = ['client_uuid' => (string) Str::uuid(), 'channel_code' => $channel, 'amount' => $amount] + $extra;
        if ($channel !== 'cash' && ! array_key_exists('receipt_file_id', $extra)) {
            $body['receipt_file_id'] = ReceiptFiles::upload($test, $as);
        }
        loginAs($as);

        return $test->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/orders/{$orderId}/payments", $body);
    }

    /** A payment_refunds row for $payment, as step 4.3d will write it. */
    public static function refund(Payment $payment, string $amount, CarbonInterface $at, int $byUserId): int
    {
        return (int) DB::table('payment_refunds')->insertGetId([
            'branch_id' => $payment->branch_id,
            'client_uuid' => (string) Str::uuid(),
            'order_id' => $payment->order_id,
            'order_refund_id' => OrderRefundRows::make($payment->branch_id, $payment->order_id, $byUserId, $amount, $at),
            'payment_id' => $payment->id,
            'channel_id' => $payment->channel_id,
            'amount' => $amount,
            'refunded_by_user_id' => $byUserId,
            'approved_by_user_id' => $byUserId,
            'refunded_at' => CarbonImmutable::instance($at)->setTimezone('UTC')->format('Y-m-d H:i:s'),
            'created_at' => CarbonImmutable::instance($at)->setTimezone('UTC')->format('Y-m-d H:i:s'),
        ]);
    }
}

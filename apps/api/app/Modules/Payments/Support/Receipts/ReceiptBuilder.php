<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support\Receipts;

use App\Modules\Orders\Contracts\Orders;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Modules\Payments\Models\ReceiptSettings;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Time\EthiopianDate;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Gathers what one receipt prints (phase-4 plan section 4.5, step 4.3e): the order through Orders::forReceipt (number, date, type, table,
 * the charged lines with their modifiers, the money), and Payments' own rows: the payments that are not voided (oldest first), the money
 * paid back (payment_refunds, empty until step 4.3d), the channels' names in both languages (removed channels included) and the receipt
 * settings (or their defaults). It reads no person's name: the receipt has none.
 *
 * An order paid in full (settled, paid or closed) has a receipt, dated when it was settled. One that is not has a pro-forma when asked
 * ($proforma), dated now, and none otherwise (409 payments.receipt.unpaid {status}); a cancelled or voided order has neither. The locale is
 * the one asked, else both when the settings show Amharic and English when they do not.
 */
final class ReceiptBuilder
{
    /** @var list<string> */
    public const array LOCALES = ['en', 'am', 'both'];

    public function __construct(
        private readonly Orders $orders,
        private readonly CurrentBranch $branch,
    ) {}

    /**
     * @throws ModelNotFoundException an order of another branch, or none
     * @throws PaymentError payments.receipt.unpaid
     */
    public function build(int $orderId, ?string $locale = null, bool $proforma = false): Receipt
    {
        $order = $this->orders->forReceipt($orderId);
        if (in_array($order->status, ['cancelled', 'voided'], true) || (! $order->isSettled() && ! $proforma)) {
            throw PaymentError::receiptUnpaid($order->status);
        }

        $settings = ReceiptSettings::view(ReceiptSettings::query()->first(), $this->branch->get()->name);
        $locale = in_array($locale, self::LOCALES, true) ? $locale : ($settings['show_amharic'] ? 'both' : 'en');
        $zone = $this->branch->timezone();
        $issuedAt = ($order->isSettled() && $order->settledAt !== null ? $order->settledAt : CarbonImmutable::now())->setTimezone($zone);
        $currency = $order->totals->currency;

        $rows = Payment::query()->where('order_id', $order->orderId)->where('status', '!=', PaymentStatus::Voided->value)
            ->orderBy('received_at')->orderBy('id')->get()->all();
        $refundRows = DB::table('payment_refunds')->where('branch_id', $this->branch->id())->where('order_id', $order->orderId)
            ->orderBy('refunded_at')->orderBy('id')->get(['channel_id', 'amount', 'refunded_at'])->all();
        $channelIds = array_merge(array_map(fn (Payment $p): int => $p->channel_id, $rows), array_map(fn (object $r): int => (int) $r->channel_id, $refundRows));
        $channels = $channelIds === [] ? collect() : PaymentChannel::query()->withTrashed()->whereIn('id', array_unique($channelIds))->get()->keyBy('id');
        $name = function (int $channelId) use ($channels): array {
            $channel = $channels->get($channelId);

            return $channel instanceof PaymentChannel ? [$channel->name_en, $channel->name_am] : ['', ''];
        };

        $change = Money::zero($currency);
        $payments = [];
        foreach ($rows as $payment) {
            [$en, $am] = $name($payment->channel_id);
            $isCash = $channels->get($payment->channel_id)?->code === PaymentChannel::CASH;
            if ($payment->change_amount !== null) {
                $change = $change->plus($payment->change_amount);
            }
            $payments[] = [
                'channel_en' => $en,
                'channel_am' => $am,
                'amount' => $payment->amount->amount(),
                'reference_tail' => self::tail($payment->reference_no),
                'tendered' => $isCash ? $payment->tendered_amount?->amount() : null,
                'change' => $isCash ? $payment->change_amount?->amount() : null,
            ];
        }

        $refunded = Money::zero($currency);
        $refunds = [];
        foreach ($refundRows as $refund) {
            [$en, $am] = $name((int) $refund->channel_id);
            $refunded = $refunded->plus(Money::of((string) $refund->amount, $currency));
            $refunds[] = [
                'channel_en' => $en,
                'channel_am' => $am,
                'amount' => Money::of((string) $refund->amount, $currency)->amount(),
                'refunded_at' => CarbonImmutable::parse((string) $refund->refunded_at, 'UTC')->setTimezone($zone)->format('Y-m-d H:i'),
            ];
        }

        return new Receipt(
            business: [
                'name_en' => $settings['business_name_en'],
                'name_am' => $settings['business_name_am'],
                'address_en' => $settings['address_en'],
                'address_am' => $settings['address_am'],
                'phone' => $settings['phone'],
                'tin' => $settings['tin'],
                'footer_en' => $settings['footer_en'],
                'footer_am' => $settings['footer_am'],
            ],
            locale: $locale,
            proforma: ! $order->isSettled(),
            orderNo: $order->orderNo,
            businessDate: $order->businessDate,
            orderType: $order->type,
            tableCode: $order->tableCode,
            issuedAt: $issuedAt,
            ethiopianDate: $settings['ethiopian_dates'] ? EthiopianDate::of($issuedAt) : null,
            lines: array_map(static fn ($line): array => [
                'name_en' => $line->nameEn,
                'name_am' => $line->nameAm,
                'qty' => $line->qty,
                'unit_price' => Money::of($line->unitPrice, $currency)->plus($line->modifierPrice)->amount(),
                'line_total' => $line->lineTotal,
                'modifiers' => $line->modifiers,
            ], $order->lines),
            money: [
                'subtotal' => $order->totals->subtotal,
                'discount' => $order->totals->discount,
                'tax' => $order->totals->tax,
                'total' => $order->totals->total,
                'paid' => $order->totals->paid,
                'balance' => $order->totals->balance,
                'change' => $change->amount(),
                'refunded' => $refunded->amount(),
                'net_paid' => Money::of($order->totals->paid, $currency)->minus($refunded)->amount(),
                'currency' => $currency,
            ],
            payments: $payments,
            refunds: $refunds,
        );
    }

    /** The last 4 characters of a reference, the only part a receipt prints; null when there is none. */
    public static function tail(?string $reference): ?string
    {
        $reference = $reference === null ? '' : trim($reference);

        return $reference === '' ? null : mb_substr($reference, -4);
    }
}

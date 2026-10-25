<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Files\Files;
use App\Shared\Files\StoredFile;

/**
 * A payment as the reads answer it (phase-4 plan section 4.3, step 4.3b: GET /orders/{order}/payments, GET /payments, GET
 * /payments/{payment}). Money as decimal strings, times as ISO 8601 UTC; the receipt as {file_id, url, url_expires_at}, the URL signed
 * now (Files::signedUrl, files.url_ttl_seconds) and never stored; the object key never appears. A handful of queries for any number of
 * payments: channels (removed ones included), the people who took them, the photos, the duplicate flags and, for the lists, the orders.
 */
final class PaymentViews
{
    public function __construct(
        private readonly Files $files,
        private readonly Orders $orders,
        private readonly Duplicates $duplicates,
    ) {}

    /**
     * @param  list<Payment>  $payments
     * @return list<array<string, mixed>>
     */
    public function present(array $payments, bool $withOrder = false): array
    {
        if ($payments === []) {
            return [];
        }
        $channels = PaymentChannel::query()->withTrashed()->whereIn('id', array_unique(array_map(fn (Payment $p): int => $p->channel_id, $payments)))->get()->keyBy('id');
        $names = User::query()->whereIn('id', array_unique(array_map(fn (Payment $p): int => $p->received_by_user_id, $payments)))->pluck('name', 'id')->all();
        $fileIds = array_values(array_filter(array_map(fn (Payment $p): ?int => $p->receipt_file_id, $payments)));
        $photos = $fileIds === [] ? [] : StoredFile::query()->whereIn('id', array_unique($fileIds))->get()->keyBy('id')->all();
        $flags = $this->duplicates->flags($payments);
        $orders = $withOrder ? $this->orders->summaries(array_values(array_unique(array_map(fn (Payment $p): int => $p->order_id, $payments)))) : [];
        $ttl = (int) config('files.url_ttl_seconds');
        $expires = now()->addSeconds($ttl)->toIso8601String();

        $out = [];
        foreach ($payments as $payment) {
            $channel = $channels->get($payment->channel_id);
            $photo = $payment->receipt_file_id === null ? null : ($photos[$payment->receipt_file_id] ?? null);
            $view = [
                'id' => $payment->id,
                'order_id' => $payment->order_id,
                'client_uuid' => $payment->client_uuid,
                'channel' => $channel instanceof PaymentChannel
                    ? ['id' => $channel->id, 'code' => $channel->code, 'name_en' => $channel->name_en, 'name_am' => $channel->name_am]
                    : ['id' => $payment->channel_id, 'code' => null, 'name_en' => null, 'name_am' => null],
                'amount' => $payment->amount->amount(),
                'tendered_amount' => $payment->tendered_amount?->amount(),
                'change_amount' => $payment->change_amount?->amount(),
                'currency' => $payment->currency,
                'reference_no' => $payment->reference_no,
                'status' => $payment->status->value,
                'received_at' => $payment->received_at->toIso8601String(),
                'received_by' => ['id' => $payment->received_by_user_id, 'name' => $names[$payment->received_by_user_id] ?? null],
                'shift_id' => $payment->shift_instance_id,
                'batch_uuid' => $payment->batch_uuid,
                'receipt' => $payment->receipt_file_id === null ? null : [
                    'file_id' => $payment->receipt_file_id,
                    'url' => $photo instanceof StoredFile ? $this->files->signedUrl($photo, $ttl) : null,
                    'url_expires_at' => $photo instanceof StoredFile ? $expires : null,
                ],
                'verified_at' => $payment->verified_at?->toIso8601String(),
                'disputed_at' => $payment->disputed_at?->toIso8601String(),
                'dispute_reason' => $payment->dispute_reason,
                'refunded_amount' => $payment->refunded_amount?->amount() ?? '0.00',
                'void_reason' => $payment->void_reason,
                'voided_at' => $payment->voided_at?->toIso8601String(),
                'flags' => $flags[$payment->id] ?? ['duplicate_reference' => false, 'duplicate_receipt' => false],
            ];
            if ($withOrder) {
                $view['order'] = isset($orders[$payment->order_id]) ? $orders[$payment->order_id]->toArray() : null;
            }
            $out[] = $view;
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Time\DayRows;
use App\Shared\Time\LocalDay;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;

/**
 * What Analytics may read of Payments (phase-4 plan section 2, contract analytics->facts): payments and refunds as plain rows.
 * Reads only. A generator over a chunked query, one local day at a time, ordered (day, id); an empty range yields nothing; both
 * ends of the range are inclusive local dates in the branch's timezone (LocalDay).
 *
 * payments(): the clock is `received_at`; voided payments are left out, a disputed one is kept (it is money received, flagged).
 * refunds(): the clock is `refunded_at`; it reads `payment_refunds`, which nothing writes before step 4.3d, so it yields nothing
 * until then. Branch-scoped through the model for payments and by the current branch for refunds, resolved per use.
 */
final class Facts
{
    /** @return Generator<int, PaymentFact> */
    public function payments(LocalDay $range): Generator
    {
        return DayRows::generate(
            $range,
            'payments.id',
            fn (CarbonImmutable $start, CarbonImmutable $end) => Payment::query()->toBase()
                ->join('payment_channels', 'payment_channels.id', '=', 'payments.channel_id')
                ->select('payments.*', 'payment_channels.code as channel_code')
                ->where('payments.status', '!=', PaymentStatus::Voided->value)
                ->where('payments.received_at', '>=', DayRows::sql($start))
                ->where('payments.received_at', '<', DayRows::sql($end)),
            fn (object $row, string $day): PaymentFact => new PaymentFact(
                (int) $row->id,
                (int) $row->order_id,
                $day,
                $range->hourOf((string) $row->received_at),
                (int) $row->channel_id,
                (string) $row->channel_code,
                Money::of((string) $row->amount)->amount(),
                (string) $row->status,
                $row->reference_no !== null && $row->reference_no !== '',
                $row->receipt_photo_key !== null && $row->receipt_photo_key !== '',
                (int) $row->received_by_user_id,
                $row->shift_instance_id === null ? null : (int) $row->shift_instance_id,
                (string) DayRows::iso($row->received_at),
            ),
        );
    }

    /** @return Generator<int, RefundFact> */
    public function refunds(LocalDay $range): Generator
    {
        $branchId = app(CurrentBranch::class)->idOrNull();

        return DayRows::generate(
            $range,
            'payment_refunds.id',
            fn (CarbonImmutable $start, CarbonImmutable $end) => DB::table('payment_refunds')
                ->join('payment_channels', 'payment_channels.id', '=', 'payment_refunds.channel_id')
                ->select('payment_refunds.*', 'payment_channels.code as channel_code')
                ->when($branchId !== null, fn ($q) => $q->where('payment_refunds.branch_id', $branchId))
                ->where('payment_refunds.refunded_at', '>=', DayRows::sql($start))
                ->where('payment_refunds.refunded_at', '<', DayRows::sql($end)),
            fn (object $row, string $day): RefundFact => new RefundFact(
                (int) $row->id,
                (int) $row->order_id,
                (int) $row->payment_id,
                $day,
                (int) $row->channel_id,
                (string) $row->channel_code,
                Money::of((string) $row->amount)->amount(),
                (int) $row->refunded_by_user_id,
                $row->shift_instance_id === null ? null : (int) $row->shift_instance_id,
                (string) DayRows::iso($row->refunded_at),
            ),
        );
    }
}

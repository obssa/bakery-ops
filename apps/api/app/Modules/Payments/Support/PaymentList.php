<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Orders\Contracts\Orders;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Time\LocalDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The branch's payments as GET /payments filters them (phase-4 plan section 4.3, step 4.3b), newest first by id, keyset paged
 * (`cursor` = the last id seen; the next page is the ids below it):
 *   from / to       local dates of received_at, both ends inclusive (LocalDay: 23:59:59 in Addis Ababa is still that day);
 *   channel_id, status, shift_id (the payment's own shift), order_no (the branch's orders of that number, any business date),
 *   reference       the normalised reference (ReferenceNumber), so "ft-7 77" finds "FT777";
 *   missing_photo   a payment on any channel but cash with no receipt photo;
 *   flagged         not voided, and disputed or a duplicate (Duplicates: a reference on the same channel, or a photo's bytes, shared
 *                   with another live payment).
 * A viewer limited to their own work (a cashier, 10.2 R-N) sees the payments received since $since or taken in their own shift.
 */
final class PaymentList
{
    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 200;

    public function __construct(private readonly Orders $orders) {}

    /**
     * @param  array<string, mixed>  $filters  the validated query (ListPaymentsRequest)
     * @return Builder<Payment>
     */
    public function query(array $filters, ?PaymentScope $scope): Builder
    {
        $query = Payment::query();
        if ($scope instanceof PaymentScope) {
            $scope->apply($query);
        }
        if (is_string($filters['from'] ?? null)) {
            $query->where('payments.received_at', '>=', $this->utc(LocalDay::of($filters['from'])->utcBounds()[0]));
        }
        if (is_string($filters['to'] ?? null)) {
            $query->where('payments.received_at', '<', $this->utc(LocalDay::of($filters['to'])->utcBounds()[1]));
        }
        foreach (['channel_id' => 'payments.channel_id', 'shift_id' => 'payments.shift_instance_id'] as $key => $column) {
            if (isset($filters[$key])) {
                $query->where($column, (int) $filters[$key]);
            }
        }
        if (is_string($filters['status'] ?? null)) {
            $query->where('payments.status', $filters['status']);
        }
        if (isset($filters['order_no'])) {
            $query->whereIn('payments.order_id', $this->orders->idsNumbered((int) $filters['order_no']) ?: [0]);
        }
        if (is_string($filters['reference'] ?? null)) {
            $norm = ReferenceNumber::column($filters['reference']);
            $norm === null ? $query->whereRaw('1 = 0') : $query->where('payments.reference_norm', $norm);
        }
        if (self::on($filters['missing_photo'] ?? null)) {
            $query->whereNull('payments.receipt_file_id')
                ->whereNotIn('payments.channel_id', PaymentChannel::query()->withTrashed()->where('code', PaymentChannel::CASH)->select('id'));
        }
        if (self::on($filters['flagged'] ?? null)) {
            $query->where('payments.status', '!=', PaymentStatus::Voided->value)->where(function (Builder $flagged): void {
                $flagged->where('payments.status', PaymentStatus::Disputed->value)
                    ->orWhereExists(fn (QueryBuilder $dup) => $dup->from('payments as dup')->selectRaw('1')
                        ->whereColumn('dup.branch_id', 'payments.branch_id')
                        ->whereColumn('dup.channel_id', 'payments.channel_id')
                        ->whereColumn('dup.reference_norm', 'payments.reference_norm')
                        ->whereColumn('dup.id', '!=', 'payments.id')
                        ->where('dup.status', '!=', PaymentStatus::Voided->value))
                    ->orWhereExists(fn (QueryBuilder $dup) => $dup->from('payments as dup')->selectRaw('1')
                        ->join('stored_files as dup_file', 'dup_file.id', '=', 'dup.receipt_file_id')
                        ->join('stored_files as own_file', 'own_file.id', '=', DB::raw('payments.receipt_file_id'))
                        ->whereColumn('dup.branch_id', 'payments.branch_id')
                        ->whereColumn('dup_file.sha256', 'own_file.sha256')
                        ->whereColumn('dup.id', '!=', 'payments.id')
                        ->where('dup.status', '!=', PaymentStatus::Voided->value));
            });
        }

        return $query;
    }

    /**
     * One page, newest first: the payments and the cursor of the next page (null on the last one).
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: list<Payment>, 1: string|null}
     */
    public function page(array $filters, ?PaymentScope $scope): array
    {
        $limit = isset($filters['limit']) ? max(1, min(self::MAX_LIMIT, (int) $filters['limit'])) : self::DEFAULT_LIMIT;
        $query = $this->query($filters, $scope)->orderByDesc('payments.id')->limit($limit + 1);
        if (isset($filters['cursor'])) {
            $query->where('payments.id', '<', (int) $filters['cursor']);
        }
        $rows = $query->get(['payments.*'])->all();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return [$rows, $more && $last instanceof Payment ? (string) $last->id : null];
    }

    private static function on(mixed $flag): bool
    {
        return in_array($flag, [true, 1, '1', 'true'], true);
    }

    private function utc(CarbonImmutable $at): string
    {
        return $at->setTimezone('UTC')->format('Y-m-d H:i:s');
    }
}

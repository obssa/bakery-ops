<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Exceptions\PaymentError;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Time\LocalDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /payments/reconciliation (phase-4 plan section 4.3, step 4.3e): ?from=&to= local dates (YYYY-MM-DD, from not after to; by default the
 * last 7 days, today included, in the branch's zone; a missing `to` is today, a missing `from` six days before `to`), ?channel_id=. At most
 * payments.reconciliation_max_days (62) days: more is 422 payments.reconciliation.range_too_large {max_days}. Anything else is 422
 * validation.failed.
 */
final class ReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => array_merge(['sometimes', 'date_format:Y-m-d'], $this->has('from') ? ['after_or_equal:from'] : []),
            'channel_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @throws PaymentError payments.reconciliation.range_too_large
     */
    public function range(CurrentBranch $branch): LocalDay
    {
        $zone = $branch->timezone();
        $data = $this->validated();
        $from = isset($data['from']) && is_string($data['from']) ? $data['from'] : null;
        $to = isset($data['to']) && is_string($data['to']) ? $data['to'] : null;
        $today = CarbonImmutable::now($zone)->toDateString();
        $to ??= $from !== null && $from > $today ? $from : $today;
        $from ??= CarbonImmutable::parse($to, $zone)->subDays(6)->toDateString();

        $max = max(1, (int) config('payments.reconciliation_max_days', 62));
        $range = LocalDay::between($from, $to, $zone);
        if (count($range->days()) > $max) {
            throw PaymentError::reconciliationRangeTooLarge($max);
        }

        return $range;
    }

    public function channelId(): ?int
    {
        $id = $this->validated()['channel_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }
}

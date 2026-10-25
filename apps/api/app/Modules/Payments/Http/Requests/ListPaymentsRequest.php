<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Support\PaymentList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /payments (phase-4 plan section 4.3, step 4.3b): local dates (YYYY-MM-DD, from not after to), a channel, a status, a shift, an
 * order number, a reference, missing_photo=1, flagged=1, a cursor (the last id seen) and a limit (1 to 200, 50 by default). Anything
 * else is 422 validation.failed. Support\PaymentList applies them.
 */
final class ListPaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $flag = ['sometimes', Rule::in(['0', '1', 'true', 'false'])];

        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => array_merge(['sometimes', 'date_format:Y-m-d'], $this->has('from') ? ['after_or_equal:from'] : []),
            'channel_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(PaymentStatus::values())],
            'shift_id' => ['sometimes', 'integer', 'min:1'],
            'order_no' => ['sometimes', 'integer', 'min:1'],
            'reference' => ['sometimes', 'string', 'max:64'],
            'missing_photo' => $flag,
            'flagged' => $flag,
            'cursor' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.PaymentList::MAX_LIMIT],
        ];
    }

    /**
     * The validated filters, in a fixed order, as strings: what the list is read with and what its ETag covers.
     *
     * @return array<string, string>
     */
    public function filters(): array
    {
        $filters = [];
        foreach (array_keys($this->rules()) as $key) {
            $value = $this->validated()[$key] ?? null;
            if ($value !== null && $value !== '') {
                $filters[$key] = (string) $value;
            }
        }

        return $filters;
    }
}

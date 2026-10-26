<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Support\PaymentsExport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /payments/export (phase-4 plan section 4.3, step 4.3e): the filters of GET /payments (ListPaymentsRequest: local dates, a channel, a
 * status, a shift, an order number, a reference, missing_photo, flagged) without its paging (a cursor or a limit is 422: the file holds
 * every row the filters find), plus ?format=csv|xlsx (csv by default) and ?locale=en|am for the headers and the channel names (en).
 */
final class ExportPaymentsRequest extends FormRequest
{
    /** @var list<string> the filters, in the order GET /payments reads them */
    private const array FILTERS = ['from', 'to', 'channel_id', 'status', 'shift_id', 'order_no', 'reference', 'missing_photo', 'flagged'];

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
            'cursor' => ['prohibited'],
            'limit' => ['prohibited'],
            'format' => ['sometimes', Rule::in(PaymentsExport::FORMATS)],
            'locale' => ['sometimes', Rule::in(['en', 'am'])],
        ];
    }

    /**
     * The validated filters as strings, in a fixed order (what Support\PaymentList reads).
     *
     * @return array<string, string>
     */
    public function filters(): array
    {
        $filters = [];
        foreach (self::FILTERS as $key) {
            $value = $this->validated()[$key] ?? null;
            if ($value !== null && $value !== '') {
                $filters[$key] = (string) $value;
            }
        }

        return $filters;
    }

    public function fileFormat(): string
    {
        $format = $this->validated()['format'] ?? 'csv';

        return is_string($format) ? $format : 'csv';
    }

    public function exportLocale(): string
    {
        return ($this->validated()['locale'] ?? 'en') === 'am' ? 'am' : 'en';
    }
}

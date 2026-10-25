<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Contracts\PaymentInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /orders/{order}/payment-batches (phase-4 plan section 4.3, step 4.3b): the client's id for the batch (ADR 0003; stored as every
 * row's batch_uuid) and 1 to 6 rows, each shaped like POST /orders/{order}/payments (its own client_uuid, distinct within the batch, the
 * channel's code, the amount above zero as a decimal string, tendered cash, the reference, the receipt photo's id). That the amounts add
 * up to the balance and each row is takeable are Payments' rules (RecordPaymentBatch), answered with their own message keys.
 */
final class RecordPaymentBatchRequest extends FormRequest
{
    public const int MAX_ROWS = 6;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rows = $this->input('rows');
        if (! is_array($rows)) {
            return;
        }
        foreach ($rows as $i => $row) {
            if (is_array($row) && is_string($row['reference_no'] ?? null)) {
                $rows[$i]['reference_no'] = trim($row['reference_no']) === '' ? null : trim($row['reference_no']);
            }
        }
        $this->merge(['rows' => $rows]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'string', 'uuid'],
            'rows' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*' => ['required', 'array'],
            'rows.*.client_uuid' => ['required', 'string', 'uuid', 'distinct:ignore_case'],
            'rows.*.channel_code' => ['required', 'string', 'max:32'],
            'rows.*.amount' => ['required', 'string', 'regex:'.RecordPaymentRequest::MONEY, 'not_regex:'.RecordPaymentRequest::ZERO],
            'rows.*.tendered_amount' => ['sometimes', 'nullable', 'string', 'regex:'.RecordPaymentRequest::MONEY],
            'rows.*.reference_no' => ['sometimes', 'nullable', 'string', 'max:64'],
            'rows.*.receipt_file_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function clientUuid(): string
    {
        return strtolower((string) $this->validated()['client_uuid']);
    }

    /**
     * @return list<PaymentInput>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ((array) $this->validated()['rows'] as $row) {
            $row = (array) $row;
            $tendered = $row['tendered_amount'] ?? null;
            $reference = $row['reference_no'] ?? null;
            $file = $row['receipt_file_id'] ?? null;
            $rows[] = new PaymentInput(
                strtolower((string) $row['client_uuid']),
                (string) $row['channel_code'],
                (string) $row['amount'],
                is_string($tendered) ? $tendered : null,
                is_string($reference) && $reference !== '' ? $reference : null,
                is_numeric($file) ? (int) $file : null,
            );
        }

        return $rows;
    }
}

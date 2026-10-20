<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /orders/{order}/payments: the client's id for the payment (ADR 0003), the channel's code, the amount as a
 * decimal string (up to 10 integer digits and 2 decimals, above zero: money is never a JSON number, ADR 0004), the cash
 * handed over for cash, and the bank's or wallet's reference when the channel asks for one. Whether the channel is
 * usable, the amount is the balance and the cash covers it are Payments' rules (RecordPayment), answered with their own
 * message keys.
 */
final class RecordPaymentRequest extends FormRequest
{
    private const string MONEY = '/\A[0-9]{1,10}(\.[0-9]{1,2})?\z/';

    private const string ZERO = '/\A0+(\.0+)?\z/';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $reference = $this->input('reference_no');
        if (is_string($reference)) {
            $this->merge(['reference_no' => trim($reference) === '' ? null : trim($reference)]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'string', 'uuid'],
            'channel_code' => ['required', 'string', 'max:32'],
            'amount' => ['required', 'string', 'regex:'.self::MONEY, 'not_regex:'.self::ZERO],
            'tendered_amount' => ['sometimes', 'nullable', 'string', 'regex:'.self::MONEY],
            'reference_no' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    public function clientUuid(): string
    {
        return strtolower((string) $this->validated()['client_uuid']);
    }

    public function channelCode(): string
    {
        return (string) $this->validated()['channel_code'];
    }

    public function amount(): string
    {
        return (string) $this->validated()['amount'];
    }

    public function tenderedAmount(): ?string
    {
        $value = $this->validated()['tendered_amount'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function referenceNo(): ?string
    {
        $value = $this->validated()['reference_no'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /orders/{order}/refund (step 4.3d): the refund's own id (client_uuid, ADR 0003), why (free text, required, up to 255 characters;
 * blanks are no reason), the PIN of the manager or admin who approves it (their short code: 4 to 6 digits, read once for Identity's check
 * and never kept), and optionally:
 *   - lines: the order-line ids to refund (none: every refundable line); nobody chooses a disposition, each line follows its product;
 *   - allocation: [{payment_id, amount}] the shares over the order's payments, instead of last in, first out (a split bill);
 *   - refund_reference: the reference of the outgoing transfer of a digital share (up to 64 characters, the column's size);
 *   - refund_proof_file_id: the photo of that transfer, a refund_proof upload (POST /files).
 * Anything else in the body is ignored.
 */
final class RefundOrderRequest extends FormRequest
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
            'client_uuid' => ['required', 'string', 'uuid'],
            'reason' => ['required', 'string', 'max:255'],
            'pin' => ['required', 'string', 'regex:/^[0-9]{4,6}$/'],
            'lines' => ['sometimes', 'array', 'max:200'],
            'lines.*' => ['integer', 'min:1', 'distinct'],
            'allocation' => ['sometimes', 'nullable', 'array', 'max:50'],
            'allocation.*' => ['array'],
            'allocation.*.payment_id' => ['required', 'integer', 'min:1'],
            'allocation.*.amount' => ['required', 'string', 'regex:/^[0-9]{1,10}(\.[0-9]{1,2})?$/'],
            'refund_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
            'refund_proof_file_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $reason = __('orders.refund.reason_required');
        $pin = __('payments.refund.pin_required');
        $line = __('orders.refund.line_invalid');
        $allocation = __('payments.refund.allocation_invalid');

        return [
            'reason.required' => $reason,
            'reason.string' => $reason,
            'reason.max' => $reason,
            'pin.required' => $pin,
            'pin.string' => $pin,
            'pin.regex' => $pin,
            'lines.*.integer' => $line,
            'lines.*.min' => $line,
            'lines.*.distinct' => $line,
            'allocation.*.payment_id.required' => $allocation,
            'allocation.*.payment_id.integer' => $allocation,
            'allocation.*.amount.required' => $allocation,
            'allocation.*.amount.string' => $allocation,
            'allocation.*.amount.regex' => $allocation,
        ];
    }

    public function clientUuid(): string
    {
        return strtolower((string) $this->validated('client_uuid'));
    }

    public function reason(): string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? trim($reason) : '';
    }

    public function pin(): string
    {
        $pin = $this->validated('pin');

        return is_string($pin) ? $pin : '';
    }

    /** @return list<int> */
    public function lines(): array
    {
        $lines = $this->validated('lines');

        return is_array($lines) ? array_values(array_map('intval', $lines)) : [];
    }

    /** @return list<array{payment_id: int, amount: string}>|null null: last in, first out */
    public function allocation(): ?array
    {
        $allocation = $this->validated('allocation');
        if (! is_array($allocation) || $allocation === []) {
            return null;
        }

        return array_values(array_map(static fn (array $share): array => [
            'payment_id' => (int) $share['payment_id'],
            'amount' => (string) $share['amount'],
        ], $allocation));
    }

    public function reference(): ?string
    {
        $reference = $this->validated('refund_reference');
        if (! is_string($reference)) {
            return null;
        }
        $reference = trim($reference);

        return $reference === '' ? null : $reference;
    }

    public function proofFileId(): ?int
    {
        $id = $this->validated('refund_proof_file_id');

        return $id === null ? null : (int) $id;
    }
}

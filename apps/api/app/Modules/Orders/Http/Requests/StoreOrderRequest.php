<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Enums\OrderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /orders: the order's client UUID (an offline replay with a lost Idempotency-Key reaches the same order, ADR
 * 0003), dine-in or takeaway (delivery and advance exist in the schema only, phase-1 plan section 10), a dining table
 * for a dine-in order only, a note, whether to send at once (default true: the pad's Send; false keeps the cart open),
 * and the lines (ReadsLines).
 */
final class StoreOrderRequest extends FormRequest
{
    use ReadsLines;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'],
            'type' => ['required', 'string', Rule::in([OrderType::DineIn->value, OrderType::Takeaway->value])],
            'dining_table_id' => ['nullable', 'integer', 'prohibited_unless:type,'.OrderType::DineIn->value],
            'notes' => ['nullable', 'string', 'max:1000'],
            'send' => ['sometimes', 'boolean'],
        ] + $this->lineRules();
    }

    public function clientUuid(): string
    {
        return strtolower((string) $this->validated()['client_uuid']);
    }

    public function orderType(): OrderType
    {
        return OrderType::from((string) $this->validated()['type']);
    }

    public function diningTableId(): ?int
    {
        $id = $this->validated()['dining_table_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function notes(): ?string
    {
        $notes = $this->validated()['notes'] ?? null;

        return is_string($notes) && $notes !== '' ? $notes : null;
    }

    /** Send at once (the default), or keep the cart open. */
    public function sendNow(): bool
    {
        return $this->boolean('send', true);
    }
}

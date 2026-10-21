<?php

declare(strict_types=1);

namespace App\Modules\Production\Http\Requests;

use App\Modules\Production\Enums\RequestPriority;
use App\Modules\Production\Http\Rules\ProductQuantity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /production/requests: ask for a to-stock product to be made. The request's client UUID (an offline replay with a lost
 * Idempotency-Key reaches the same request, ADR 0003), the product, how many in its base unit as a positive decimal string with
 * at most 4 decimals (never a float, ADR 0004; default: the product batch size), a due time and a priority (the planner's: the
 * action refuses them without production.plan) and a note. Whether the product is one production makes is the action's
 * question (CreateRequest).
 */
final class StoreProductionRequest extends FormRequest
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
        return [
            'client_uuid' => ['required', 'uuid'],
            'product_item_id' => ['required', 'integer', 'min:1'],
            'qty' => ['sometimes', 'nullable', new ProductQuantity],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(array_map(static fn (RequestPriority $p): string => $p->value, RequestPriority::cases()))],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function clientUuid(): string
    {
        return strtolower((string) $this->validated()['client_uuid']);
    }

    public function productId(): int
    {
        return (int) $this->validated()['product_item_id'];
    }

    public function qty(): ?string
    {
        $qty = $this->validated()['qty'] ?? null;

        return $qty === null ? null : (string) $qty;
    }

    public function dueAt(): ?CarbonImmutable
    {
        $due = $this->validated()['due_at'] ?? null;

        return is_string($due) ? CarbonImmutable::parse($due) : null;
    }

    public function priority(): RequestPriority
    {
        $priority = $this->validated()['priority'] ?? null;

        return is_string($priority) ? RequestPriority::from($priority) : RequestPriority::Normal;
    }

    public function note(): ?string
    {
        $note = $this->validated()['note'] ?? null;

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}

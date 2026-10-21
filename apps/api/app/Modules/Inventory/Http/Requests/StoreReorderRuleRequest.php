<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Catalog\Http\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /reorder-rules: when an item is running low and how much to order. min_qty and reorder_qty are decimal strings in the
 * item's base unit (never a float, ADR 0004): the minimum may be zero, the quantity to reorder must be above zero.
 * location_id left out or null covers the whole branch. notify_roles left out is the default (admin and manager). Whether
 * the item and the place are live in this branch, whether every role exists and whether the place already has a rule is the
 * action's question (Reorder\ReorderRules).
 */
final class StoreReorderRuleRequest extends FormRequest
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
            'item_id' => ['required', 'integer', 'min:1'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'min_qty' => ['required', DecimalString::rate()],
            'reorder_qty' => ['required', DecimalString::quantity()],
            'notify_roles' => ['sometimes', 'array', 'min:1', 'max:20'],
            'notify_roles.*' => ['string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function itemId(): int
    {
        return (int) $this->validated()['item_id'];
    }

    public function locationId(): ?int
    {
        $id = $this->validated()['location_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function minQty(): string
    {
        return (string) $this->validated()['min_qty'];
    }

    public function reorderQty(): string
    {
        return (string) $this->validated()['reorder_qty'];
    }

    /**
     * @return list<string>|null
     */
    public function notifyRoles(): ?array
    {
        $roles = $this->validated()['notify_roles'] ?? null;

        return is_array($roles) ? array_values(array_map(static fn (mixed $role): string => (string) $role, $roles)) : null;
    }

    public function isActive(): bool
    {
        return (bool) ($this->validated()['is_active'] ?? true);
    }
}

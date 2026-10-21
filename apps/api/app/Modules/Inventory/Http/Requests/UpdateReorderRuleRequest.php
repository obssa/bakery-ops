<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Catalog\Http\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /reorder-rules/{rule}: any of the minimum, the quantity to reorder, the roles to tell and the switch; a field left out
 * stays. The item and the place of a rule never change (a field naming them is ignored): delete the rule and make another.
 */
final class UpdateReorderRuleRequest extends FormRequest
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
            'min_qty' => ['sometimes', 'required', DecimalString::rate()],
            'reorder_qty' => ['sometimes', 'required', DecimalString::quantity()],
            'notify_roles' => ['sometimes', 'array', 'min:1', 'max:20'],
            'notify_roles.*' => ['string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{min_qty?: string, reorder_qty?: string, notify_roles?: list<string>, is_active?: bool}
     */
    public function changes(): array
    {
        $data = $this->validated();
        $changes = [];
        if (array_key_exists('min_qty', $data)) {
            $changes['min_qty'] = (string) $data['min_qty'];
        }
        if (array_key_exists('reorder_qty', $data)) {
            $changes['reorder_qty'] = (string) $data['reorder_qty'];
        }
        if (array_key_exists('notify_roles', $data) && is_array($data['notify_roles'])) {
            $changes['notify_roles'] = array_values(array_map(static fn (mixed $role): string => (string) $role, $data['notify_roles']));
        }
        if (array_key_exists('is_active', $data)) {
            $changes['is_active'] = (bool) $data['is_active'];
        }

        return $changes;
    }
}

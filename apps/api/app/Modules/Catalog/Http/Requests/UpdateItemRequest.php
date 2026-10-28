<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Http\Rules\DecimalString;
use App\Modules\Catalog\Models\Item;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /items/{item}: any subset of the editable attributes. The kind is fixed (it decides which inventory the
 * item's stock belongs to); an ingredient stays bought in; the unit may change only within the same base unit (the
 * controller checks, since every stock movement of the item is written in that base). Step 4.1b: the two refund settings, booleans;
 * refund_restock = true where nothing can go back into stock is the controller's refusal (catalog.item.restock_not_stocked).
 */
final class UpdateItemRequest extends FormRequest
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
        $branchId = app(CurrentBranch::class)->idOrNull();
        // the bound item (null only when the rules are read outside a request, e.g. by the OpenAPI export)
        $item = $this->route('item');
        $item = $item instanceof Item ? $item : null;
        $isIngredient = $item?->isIngredient() ?? false;
        $stationExists = Rule::exists('stations', 'id')->where('branch_id', $branchId)->whereNull('deleted_at');
        $sameKind = Rule::exists('categories', 'id')->where('branch_id', $branchId)->whereNull('deleted_at');
        if ($item !== null) {
            $sameKind = $sameKind->where('kind', $item->kind->value);
        }

        return [
            'kind' => ['prohibited'],
            'fulfilment' => ['sometimes', 'string', Rule::in($isIngredient ? [Fulfilment::BoughtIn->value] : Fulfilment::values())],
            'name_en' => ['sometimes', 'required', 'string', 'max:160'],
            'name_am' => ['sometimes', 'required', 'string', 'max:160'],
            'category_id' => ['sometimes', 'nullable', 'integer', $sameKind],
            'unit_id' => ['sometimes', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'sku' => ['sometimes', 'nullable', 'string', 'max:64', Rule::unique('items', 'sku')->where('branch_id', $branchId)->ignore($item?->id)],
            'is_active' => ['sometimes', 'boolean'],
            'is_new_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // a batch size and a producing station belong to products only
            'default_batch_qty' => $isIngredient ? ['prohibited'] : ['sometimes', 'nullable', DecimalString::quantity()],
            'production_station_id' => $isIngredient ? ['prohibited'] : ['sometimes', 'nullable', 'integer', $stationExists],
            'refundable' => ['sometimes', 'boolean'],
            'refund_restock' => ['sometimes', 'boolean'],
        ];
    }

    public function unitId(): ?int
    {
        $id = $this->validated()['unit_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array<string, mixed> only the attributes present in the request
     */
    public function changes(): array
    {
        $data = $this->validated();
        $out = [];
        foreach (['name_en', 'name_am', 'sku', 'is_new_until', 'default_batch_qty'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key] === null ? null : (string) $data[$key];
            }
        }
        foreach (['category_id', 'unit_id', 'production_station_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key] === null ? null : (int) $data[$key];
            }
        }
        if (array_key_exists('fulfilment', $data)) {
            $out['fulfilment'] = Fulfilment::from((string) $data['fulfilment']);
        }
        if (array_key_exists('is_active', $data)) {
            $out['is_active'] = (bool) $data['is_active'];
        }

        return $out + StoreItemRequest::refundSettings($data);
    }
}

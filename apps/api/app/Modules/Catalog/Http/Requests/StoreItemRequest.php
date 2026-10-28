<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Http\Rules\DecimalString;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /items: an ingredient or a product of the admin's branch, named in both languages (rule 9). A product says how
 * it is fulfilled; an ingredient is always bought in (the default). The category holds items of the same kind; sku is
 * unique per branch; the batch size and the producing station belong to products only. Quantities are decimal
 * strings (ADR 0004). Step 4.1b: the two refund settings, booleans, optional (left out, Item takes their natural value); the
 * controller refuses refund_restock = true where nothing can go back into stock (catalog.item.restock_not_stocked).
 */
final class StoreItemRequest extends FormRequest
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
        $kind = $this->input('kind');
        $kind = is_string($kind) ? $kind : '';

        return [
            'kind' => ['required', 'string', Rule::in(ItemKind::values())],
            'fulfilment' => $kind === ItemKind::Ingredient->value
                ? ['sometimes', 'string', Rule::in([Fulfilment::BoughtIn->value])]
                : ['required', 'string', Rule::in(Fulfilment::values())],
            'name_en' => ['required', 'string', 'max:160'],
            'name_am' => ['required', 'string', 'max:160'],
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')
                ->where('branch_id', $branchId)->where('kind', $kind)->whereNull('deleted_at')],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'sku' => ['sometimes', 'nullable', 'string', 'max:64', Rule::unique('items', 'sku')->where('branch_id', $branchId)],
            'is_active' => ['sometimes', 'boolean'],
            'is_new_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'default_batch_qty' => ['sometimes', 'nullable', 'prohibited_if:kind,ingredient', DecimalString::quantity()],
            'production_station_id' => ['sometimes', 'nullable', 'prohibited_if:kind,ingredient', 'integer', Rule::exists('stations', 'id')
                ->where('branch_id', $branchId)->whereNull('deleted_at')],
            'refundable' => ['sometimes', 'boolean'],
            'refund_restock' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed> the item attributes (branch_id and the actor stamp are added by the controller)
     */
    public function itemAttributes(): array
    {
        $data = $this->validated();
        $kind = ItemKind::from((string) $data['kind']);

        return [
            'kind' => $kind,
            'fulfilment' => $kind === ItemKind::Ingredient ? Fulfilment::BoughtIn : Fulfilment::from((string) $data['fulfilment']),
            'name_en' => (string) $data['name_en'],
            'name_am' => (string) $data['name_am'],
            'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : null,
            'unit_id' => (int) $data['unit_id'],
            'sku' => isset($data['sku']) ? (string) $data['sku'] : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'is_new_until' => isset($data['is_new_until']) ? (string) $data['is_new_until'] : null,
            'default_batch_qty' => isset($data['default_batch_qty']) ? (string) $data['default_batch_qty'] : null,
            'production_station_id' => isset($data['production_station_id']) ? (int) $data['production_station_id'] : null,
        ] + self::refundSettings($data);
    }

    /**
     * The refund settings the request names, as booleans; one left out is not in the array (Item gives it its natural value).
     *
     * @param  array<string, mixed>  $data
     * @return array{refundable?: bool, refund_restock?: bool}
     */
    public static function refundSettings(array $data): array
    {
        $out = [];
        foreach (['refundable', 'refund_restock'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = filter_var($data[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $out;
    }
}

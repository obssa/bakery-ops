<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Http\Rules\DecimalString;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /items/{item}/modifiers: a bilingual name (rule 9), a price delta (signed money, decimal string, default 0) and
 * an optional recipe delta: signed, non-zero quantities of live items of the branch, each in a unit that converts to
 * the item's base unit (checked by the controller against the database).
 */
final class StoreModifierRequest extends FormRequest
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

        return [
            'name_en' => ['required', 'string', 'max:120'],
            'name_am' => ['required', 'string', 'max:120'],
            'price_delta' => ['sometimes', 'nullable', DecimalString::signedMoney()],
            'recipe_delta' => ['sometimes', 'nullable', 'array', 'max:20'],
            'recipe_delta.*' => ['array'],
            'recipe_delta.*.ingredient_item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('branch_id', $branchId)->whereNull('deleted_at')],
            'recipe_delta.*.qty' => ['required', DecimalString::signedQuantity()],
            'recipe_delta.*.unit_id' => ['required', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
        ];
    }

    public function nameEn(): string
    {
        return (string) $this->validated()['name_en'];
    }

    public function nameAm(): string
    {
        return (string) $this->validated()['name_am'];
    }

    public function priceDelta(): string
    {
        $delta = $this->validated()['price_delta'] ?? null;

        return $delta === null ? '0' : (string) $delta;
    }

    /**
     * @return list<array{ingredient_item_id: int, qty: string, unit_id: int}>
     */
    public function recipeDelta(): array
    {
        $out = [];
        $rows = $this->validated()['recipe_delta'] ?? [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = [
                'ingredient_item_id' => (int) $row['ingredient_item_id'],
                'qty' => (string) $row['qty'],
                'unit_id' => (int) $row['unit_id'],
            ];
        }

        return $out;
    }
}

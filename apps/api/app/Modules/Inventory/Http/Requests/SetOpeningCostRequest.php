<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Catalog\Http\Rules\DecimalString;
use App\Shared\Values\UnitCost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /inventory/costs/{item}/opening: the cost of the stock already on hand, per $unit_id (a decimal string with at most 4
 * decimals, never a float). SetOpeningCost converts it to a cost per base unit.
 */
final class SetOpeningCostRequest extends FormRequest
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
            'unit_cost' => ['required', DecimalString::rate()],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
        ];
    }

    public function unitCost(): UnitCost
    {
        return UnitCost::of((string) $this->validated()['unit_cost']);
    }

    public function unitId(): int
    {
        return (int) $this->validated()['unit_id'];
    }
}

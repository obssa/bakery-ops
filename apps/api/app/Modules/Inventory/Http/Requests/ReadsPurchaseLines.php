<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Catalog\Http\Rules\DecimalString;
use App\Modules\Inventory\Purchases\LineInput;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The lines of a purchase as POST /purchases and PUT /purchases/{purchase}/lines take them: a client UUID per line (distinct,
 * ADR 0003), the item, the quantity as a positive decimal string with at most 4 decimals in a unit, the cost per that unit
 * as a decimal string with at most 4 decimals (never a float, ADR 0004). The list may be empty (a draft with no lines yet).
 * Whether the item can be bought, whether the unit converts and what the cost comes to per base unit is LineResolver's
 * question, answered per line.
 *
 * @phpstan-require-extends FormRequest
 */
trait ReadsPurchaseLines
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function lineRules(): array
    {
        return [
            'lines' => ['present', 'array', 'max:100'],
            'lines.*' => ['array'],
            'lines.*.client_uuid' => ['required', 'uuid', 'distinct:ignore_case'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', DecimalString::quantity()],
            'lines.*.unit_id' => ['required', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'lines.*.unit_cost' => ['required', DecimalString::rate()],
        ];
    }

    /**
     * @return list<LineInput>
     */
    public function lines(): array
    {
        $rows = $this->validated()['lines'] ?? [];
        $lines = [];
        foreach (is_array($rows) ? array_values($rows) : [] as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $lines[] = new LineInput(
                $index,
                strtolower((string) $row['client_uuid']),
                (int) $row['item_id'],
                Quantity::of((string) $row['qty']),
                (int) $row['unit_id'],
                UnitCost::of((string) $row['unit_cost']),
            );
        }

        return $lines;
    }
}

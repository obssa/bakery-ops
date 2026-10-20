<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Catalog\Http\Rules\DecimalString;
use App\Modules\Orders\Support\LineInput;
use App\Shared\Values\Quantity;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The lines of a cart as POST /orders and PUT /orders/{order}/lines take them: a client UUID per line (distinct, ADR
 * 0003), the product, the quantity as a positive decimal string with at most 4 decimals (never a float, ADR 0004),
 * the modifiers chosen and a note for the station. Whether the product can be sold, at what price and from where is
 * the action's question (LineResolver), answered per line.
 *
 * @phpstan-require-extends FormRequest
 */
trait ReadsLines
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function lineRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*' => ['array'],
            'lines.*.client_uuid' => ['required', 'uuid', 'distinct:ignore_case'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', DecimalString::quantity()],
            'lines.*.modifier_ids' => ['sometimes', 'nullable', 'array', 'max:10'],
            'lines.*.modifier_ids.*' => ['integer', 'distinct'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
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
            $modifierIds = [];
            foreach (is_array($row['modifier_ids'] ?? null) ? $row['modifier_ids'] : [] as $id) {
                $modifierIds[] = (int) $id;
            }
            $notes = $row['notes'] ?? null;
            $lines[] = new LineInput(
                $index,
                strtolower((string) $row['client_uuid']),
                (int) $row['item_id'],
                Quantity::of((string) $row['qty']),
                $modifierIds,
                is_string($notes) && $notes !== '' ? $notes : null,
            );
        }

        return $lines;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /stock-counts/{count}/lines: `lines` (each an `item_id` and any of `counted_qty`, `confirm`, `reason_id`, `reason_note`) and/or
 * `confirm_all` (the explicit "nothing changed"). The shape is checked here; a counted figure is deliberately not: the action reads
 * it as a decimal string or a whole JSON number and answers 422 shifts.count.qty_invalid naming the item when it is anything else,
 * so a client gets the same error whatever is wrong with the figure. A key that is absent leaves that field alone; `null` clears it.
 */
final class SetCountLinesRequest extends FormRequest
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
            'lines' => ['required_without:confirm_all', 'array', 'max:500'],
            'lines.*' => ['array'],
            'lines.*.item_id' => ['required', 'integer', 'min:1'],
            'lines.*.confirm' => ['sometimes', 'nullable', 'boolean'],
            'lines.*.reason_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'lines.*.reason_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'confirm_all' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<array<string, mixed>> the lines as sent (a counted_qty of any type included), in order
     */
    public function lines(): array
    {
        $this->validated();
        $lines = $this->input('lines', []);

        return is_array($lines) ? array_values($lines) : [];
    }

    public function confirmAll(): bool
    {
        return (bool) ($this->validated()['confirm_all'] ?? false);
    }
}

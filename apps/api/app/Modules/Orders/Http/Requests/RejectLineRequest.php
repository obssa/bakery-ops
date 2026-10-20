<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Enums\RejectReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /order-lines/{line}/reject: why the station gives the line back, one of the fixed list (RejectReason:
 * ingredient_spoiled, equipment_down, out_of_stock, other) and a note, which `other` needs (up to 500 characters, the
 * column's size; blanks are no note).
 */
final class RejectLineRequest extends FormRequest
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
            'reason' => ['required', 'string', Rule::in(RejectReason::values())],
            'note' => ['nullable', 'string', 'max:500', 'required_if:reason,'.RejectReason::Other->value],
        ];
    }

    public function reason(): RejectReason
    {
        $reason = $this->validated('reason');

        return RejectReason::from(is_string($reason) ? $reason : '');
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}

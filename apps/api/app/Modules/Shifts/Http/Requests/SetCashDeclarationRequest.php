<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /stock-counts/{count}/cash: `denominations` (an object, {"200": 3, ..}; always the whole count, an empty object is a drawer with
 * nothing in it) and any of `float_left_total`, `channels`, `reason_id`, `reason_note`. The shape is checked here; the denominations, the
 * sums and the channels are checked by the action, which answers 422 shifts.cash.* naming what is wrong, so a client gets the same error
 * whatever the mistake. A key that is absent leaves that part alone, `null` clears it. Any total sent is ignored: the server works it out.
 */
final class SetCashDeclarationRequest extends FormRequest
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
            'denominations' => ['present', 'array'],
            'float_left_total' => ['sometimes', 'nullable'],
            'channels' => ['sometimes', 'nullable', 'array', 'max:50'],
            'reason_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'reason_note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed> only the keys that were sent
     */
    public function declaration(): array
    {
        $this->validated();

        return $this->only(['denominations', 'float_left_total', 'channels', 'reason_id', 'reason_note']);
    }
}

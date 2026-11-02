<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /orders/{order}/print (step 5.3a, phase-5 plan 4.3): {copies?: 1 to 3, one when left out; client_uuid: the till's id of this
 * request, a UUID}. Anything else is 422 validation.failed.
 */
final class PrintOrderRequest extends FormRequest
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
            'copies' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'client_uuid' => ['required', 'string', 'uuid'],
        ];
    }

    public function copies(): int
    {
        $copies = $this->validated()['copies'] ?? null;

        return is_numeric($copies) ? (int) $copies : 1;
    }

    public function clientUuid(): string
    {
        return strtolower((string) $this->validated()['client_uuid']);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /inventory/costs/{item}?limit=: how many history rows to return, newest first (default 50, at most 200).
 */
final class ShowCostRequest extends FormRequest
{
    public const int DEFAULT_LIMIT = 50;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated()['limit'] ?? self::DEFAULT_LIMIT);
    }
}

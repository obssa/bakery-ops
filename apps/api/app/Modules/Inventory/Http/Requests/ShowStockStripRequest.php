<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /stations/{station}/stock-strip?limit=: how many lines the strip shows (default 5, up to 50).
 */
final class ShowStockStripRequest extends FormRequest
{
    public const int DEFAULT_LIMIT = 5;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['limit' => ['sometimes', 'integer', 'min:1', 'max:50']];
    }

    public function limit(): int
    {
        return (int) ($this->validated()['limit'] ?? self::DEFAULT_LIMIT);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /stock-alerts?active=1&type=low|out&limit=: the stock alert episodes of the branch, newest first. `active=1` keeps the open
 * ones; without it the cleared ones are listed too, at most `limit` (default 100, up to 200).
 */
final class ListStockAlertsRequest extends FormRequest
{
    public const int DEFAULT_LIMIT = 100;

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
            'active' => ['sometimes', 'boolean'],
            'type' => ['sometimes', Rule::in(['low', 'out'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function activeOnly(): bool
    {
        return $this->boolean('active');
    }

    public function type(): ?string
    {
        $type = $this->validated()['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    public function limit(): int
    {
        return (int) ($this->validated()['limit'] ?? self::DEFAULT_LIMIT);
    }
}

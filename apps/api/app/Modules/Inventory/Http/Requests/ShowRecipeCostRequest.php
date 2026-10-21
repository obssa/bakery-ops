<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /inventory/recipe-costs/{product}?version=: the recipe version to cost (a positive whole number); without it the
 * active one.
 */
final class ShowRecipeCostRequest extends FormRequest
{
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
            'version' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
        ];
    }

    public function version(): ?int
    {
        $version = $this->validated()['version'] ?? null;

        return $version === null ? null : (int) $version;
    }
}

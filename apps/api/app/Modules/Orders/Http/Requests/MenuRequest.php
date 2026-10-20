<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Inventory\Contracts\Locations;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /menu: ?location=<id> (every product at one location of this branch) and ?include_unavailable=1. Both optional.
 */
final class MenuRequest extends FormRequest
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
            'location' => ['sometimes', 'integer', 'min:1', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_numeric($value) && ! app(Locations::class)->has((int) $value)) {
                    $fail('validation.exists');
                }
            }],
            'include_unavailable' => ['sometimes', 'boolean'],
        ];
    }

    public function locationId(): ?int
    {
        $id = $this->validated()['location'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function includeUnavailable(): bool
    {
        return array_key_exists('include_unavailable', $this->validated()) && $this->boolean('include_unavailable');
    }
}

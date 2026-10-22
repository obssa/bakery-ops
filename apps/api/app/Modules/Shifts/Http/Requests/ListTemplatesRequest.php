<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /shift-templates?active=1&station_id=: the live templates of the branch, optionally only the active ones and only those that
 * serve one station.
 */
final class ListTemplatesRequest extends FormRequest
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
            'active' => ['sometimes', 'boolean'],
            'station_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function activeOnly(): bool
    {
        return (bool) ($this->validated()['active'] ?? false);
    }

    public function stationId(): ?int
    {
        $id = $this->validated()['station_id'] ?? null;

        return $id === null ? null : (int) $id;
    }
}

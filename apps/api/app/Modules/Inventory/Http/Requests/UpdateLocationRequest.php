<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /locations/{location}: rename it or tie it to another station (or none). The type is fixed: it decides where
 * availability is computed (display-case products at the display_case location), so a store never turns into one.
 */
final class UpdateLocationRequest extends FormRequest
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
            'type' => ['prohibited'],
            'name_en' => ['sometimes', 'required', 'string', 'max:120'],
            'name_am' => ['sometimes', 'required', 'string', 'max:120'],
            'station_id' => ['sometimes', 'nullable', 'integer', Rule::exists('stations', 'id')
                ->where('branch_id', app(CurrentBranch::class)->idOrNull())->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string|int|null> only the attributes present in the request
     */
    public function changes(): array
    {
        $data = $this->validated();
        $out = [];
        foreach (['name_en', 'name_am'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = (string) $data[$key];
            }
        }
        if (array_key_exists('station_id', $data)) {
            $out['station_id'] = $data['station_id'] === null ? null : (int) $data['station_id'];
        }

        return $out;
    }
}

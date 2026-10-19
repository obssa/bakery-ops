<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\LocationType;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /locations: a place stock is held in, named in both languages (rule 9), of one of the location types, optionally
 * tied to a station of the admin's branch.
 */
final class StoreLocationRequest extends FormRequest
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
            'name_en' => ['required', 'string', 'max:120'],
            'name_am' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::in(LocationType::values())],
            'station_id' => ['sometimes', 'nullable', 'integer', Rule::exists('stations', 'id')
                ->where('branch_id', app(CurrentBranch::class)->idOrNull())->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array{name_en: string, name_am: string, type: LocationType, station_id: int|null}
     */
    public function locationAttributes(): array
    {
        $data = $this->validated();

        return [
            'name_en' => (string) $data['name_en'],
            'name_am' => (string) $data['name_am'],
            'type' => LocationType::from((string) $data['type']),
            'station_id' => isset($data['station_id']) ? (int) $data['station_id'] : null,
        ];
    }
}

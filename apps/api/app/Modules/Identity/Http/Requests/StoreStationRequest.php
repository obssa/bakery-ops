<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Shared\Enums\StationCode;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /stations: one station per code per branch (Shared's stations table), named in both languages (rule 9).
 */
final class StoreStationRequest extends FormRequest
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
        $branchId = app(CurrentBranch::class)->idOrNull();

        return [
            'code' => ['required', 'string', Rule::in(StationCode::values()),
                Rule::unique('stations', 'code')->where('branch_id', $branchId)->whereNull('deleted_at')],
            'name_en' => ['required', 'string', 'max:120'],
            'name_am' => ['required', 'string', 'max:120'],
            'requires_overseer' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stationAttributes(): array
    {
        $data = $this->validated();

        return [
            'code' => StationCode::from((string) $data['code']),
            'name_en' => (string) $data['name_en'],
            'name_am' => (string) $data['name_am'],
            'requires_overseer' => (bool) ($data['requires_overseer'] ?? false),
            'sort' => (int) ($data['sort'] ?? 0),
        ];
    }
}

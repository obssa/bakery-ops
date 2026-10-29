<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /devices/{id}/rename (step 5.1a, phase-5 plan 4.1): a new name and, when the key is sent, a new home station of the branch (null
 * clears it; left out, it stays). No row is created, so the Idempotency-Key alone makes a retry safe.
 */
final class RenameDeviceRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'station_id' => ['sometimes', 'nullable', 'integer',
                Rule::exists('stations', 'id')->where('branch_id', app(CurrentBranch::class)->idOrNull())->whereNull('deleted_at')],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->validated()['name']);
    }

    public function movesStation(): bool
    {
        return array_key_exists('station_id', $this->validated());
    }

    public function stationId(): ?int
    {
        $station = $this->validated()['station_id'] ?? null;

        return $station === null ? null : (int) $station;
    }
}

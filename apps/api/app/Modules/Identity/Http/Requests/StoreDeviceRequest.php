<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /devices: enrol a device by the fingerprint it will send as X-Device-Id. Since step 5.1a (phase-5 plan 4.1): `kind` (tablet when
 * left out; anything outside DeviceKind is 422 identity.device.kind_invalid), an optional home station of the branch, and a client_uuid
 * (ADR 0003: the same one again answers the device it made). A fingerprint enrolled before, in any branch and even deleted, is
 * 422 identity.device.fingerprint_taken (a fingerprint is a physical device); both checks are the action's, after these rules.
 */
final class StoreDeviceRequest extends FormRequest
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
            'fingerprint' => ['required', 'string', 'max:191'],
            'kind' => ['sometimes', 'nullable', 'string', 'max:32'],
            'station_id' => ['sometimes', 'nullable', 'integer',
                Rule::exists('stations', 'id')->where('branch_id', app(CurrentBranch::class)->idOrNull())->whereNull('deleted_at')],
            'client_uuid' => ['required', 'uuid'],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->validated()['name']);
    }

    public function fingerprint(): string
    {
        return trim((string) $this->validated()['fingerprint']);
    }

    /** As sent (null when left out); the action reads it against DeviceKind. */
    public function kind(): ?string
    {
        $kind = $this->validated()['kind'] ?? null;

        return $kind === null ? null : (string) $kind;
    }

    public function stationId(): ?int
    {
        $station = $this->validated()['station_id'] ?? null;

        return $station === null ? null : (int) $station;
    }

    public function clientUuid(): string
    {
        return strtolower((string) $this->validated()['client_uuid']);
    }
}

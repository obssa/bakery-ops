<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Enums\DeviceKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /devices (step 5.1a, phase-5 plan 4.1): `?kind=` one of DeviceKind, `?revoked=` 1 for the revoked devices only, 0 for the others.
 */
final class ListDevicesRequest extends FormRequest
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
            'kind' => ['sometimes', 'string', Rule::in(DeviceKind::values())],
            'revoked' => ['sometimes', 'boolean'],
        ];
    }

    public function kind(): ?DeviceKind
    {
        $kind = $this->validated()['kind'] ?? null;

        return $kind === null ? null : DeviceKind::from((string) $kind);
    }

    public function revoked(): ?bool
    {
        $revoked = $this->validated()['revoked'] ?? null;

        return $revoked === null ? null : filter_var($revoked, FILTER_VALIDATE_BOOLEAN);
    }
}

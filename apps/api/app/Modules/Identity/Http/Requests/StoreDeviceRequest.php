<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /devices: enrol a pad or till by the fingerprint it will send as X-Device-Id (globally unique, including
 * soft-deleted rows: a fingerprint is a physical device).
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
            'fingerprint' => ['required', 'string', 'max:191', 'unique:devices,fingerprint'],
        ];
    }

    public function name(): string
    {
        return (string) $this->validated()['name'];
    }

    public function fingerprint(): string
    {
        return trim((string) $this->validated()['fingerprint']);
    }
}

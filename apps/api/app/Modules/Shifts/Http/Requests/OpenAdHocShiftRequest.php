<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /stations/{station}/shifts/open-ad-hoc: the call-in. The client UUID of the shift it may make, and optionally the person it
 * is opened for (the admin's; everyone else opens it for themselves).
 */
final class OpenAdHocShiftRequest extends FormRequest
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
            'client_uuid' => ['required', 'uuid'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function clientUuid(): string
    {
        return strtolower((string) $this->validated()['client_uuid']);
    }

    public function userId(): ?int
    {
        $id = $this->validated()['user_id'] ?? null;

        return $id === null ? null : (int) $id;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyShortCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^\d{4,6}$/'],
            // the pad may name the person whose code is typed (per-user lockout) and, before enrolment, its branch
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
        ];
    }

    public function code(): string
    {
        return $this->string('code')->toString();
    }

    public function userId(): ?int
    {
        $id = $this->input('user_id');

        return is_numeric($id) ? (int) $id : null;
    }

    public function branchId(): ?int
    {
        $id = $this->input('branch_id');

        return is_numeric($id) ? (int) $id : null;
    }
}

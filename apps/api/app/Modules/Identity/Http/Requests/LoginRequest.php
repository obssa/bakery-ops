<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
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
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'max:255'],
            'branch_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function phone(): string
    {
        return trim($this->string('phone')->toString());
    }

    public function password(): string
    {
        return $this->string('password')->toString();
    }

    public function branchId(): ?int
    {
        $id = $this->input('branch_id');

        return is_numeric($id) ? (int) $id : null;
    }
}

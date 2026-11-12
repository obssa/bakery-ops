<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** TEMPORARY (ADR 0025): POST /demo-login {role}. Which roles have a demo account is DemoLogins' answer, not a validation rule. */
final class DemoLoginRequest extends FormRequest
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
            'role' => ['required', 'string', 'max:32'],
        ];
    }

    public function role(): string
    {
        return $this->string('role')->toString();
    }
}

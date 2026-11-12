<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * TEMPORARY (ADR 0025): PUT /brand/demo-login {on: true|false}, the demo branch's switch of the sign-in page's demo buttons. Anything but a
 * boolean is 422 validation.failed.
 */
final class UpdateDemoLoginRequest extends FormRequest
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
            'on' => ['required', 'boolean'],
        ];
    }

    public function on(): bool
    {
        return (bool) $this->validated()['on'];
    }
}

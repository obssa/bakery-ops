<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Contracts\PermissionRegistry;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /users: a person of the admin's branch with one role, optional direct permission overrides, an optional short
 * code (checked again for uniqueness by ShortCodes) and a locale.
 */
final class StoreUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9]{9,15}$/',
                Rule::unique('users', 'phone')->where('branch_id', $branchId)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'locale' => ['required', Rule::in(['en', 'am'])],
            'role' => ['required', Rule::in(PermissionRegistry::ROLES)],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionRegistry::permissions())],
            'short_code' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4,6}$/'],
            'is_active' => ['sometimes', 'boolean'],
            'photo_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ];
    }

    /**
     * @return array<string, mixed> the user attributes (no role, permissions or short code)
     */
    public function userAttributes(): array
    {
        $data = $this->validated();

        return [
            'name' => (string) $data['name'],
            'phone' => trim((string) $data['phone']),
            'email' => isset($data['email']) ? (string) $data['email'] : null,
            'password' => (string) $data['password'],
            'locale' => (string) $data['locale'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'photo_url' => isset($data['photo_url']) ? (string) $data['photo_url'] : null,
        ];
    }

    public function role(): string
    {
        return (string) $this->validated()['role'];
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        $perms = $this->validated()['permissions'] ?? [];

        return is_array($perms) ? array_values(array_map(fn ($p): string => (string) $p, $perms)) : [];
    }

    public function shortCode(): ?string
    {
        $code = $this->validated()['short_code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Contracts\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /users/{id}: any subset of the editable attributes, the role, and the direct permission overrides (sent as
 * the complete new list). Phone is not editable here: it is the login id.
 */
final class UpdateUserRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'password' => ['sometimes', 'string', 'min:8', 'max:255'],
            'locale' => ['sometimes', Rule::in(['en', 'am'])],
            'role' => ['sometimes', Rule::in(PermissionRegistry::ROLES)],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionRegistry::permissions())],
            'is_active' => ['sometimes', 'boolean'],
            'photo_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ];
    }

    /**
     * @return array<string, mixed> only the attributes present in the request
     */
    public function changes(): array
    {
        $data = $this->validated();
        $out = [];
        foreach (['name', 'email', 'password', 'locale', 'photo_url'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key] === null ? null : (string) $data[$key];
            }
        }
        if (array_key_exists('is_active', $data)) {
            $out['is_active'] = (bool) $data['is_active'];
        }

        return $out;
    }

    public function role(): ?string
    {
        $role = $this->validated()['role'] ?? null;

        return is_string($role) ? $role : null;
    }

    /**
     * @return list<string>|null null when the request does not touch the overrides
     */
    public function permissions(): ?array
    {
        $data = $this->validated();
        if (! array_key_exists('permissions', $data)) {
            return null;
        }
        $perms = $data['permissions'];

        return is_array($perms) ? array_values(array_map(fn ($p): string => (string) $p, $perms)) : [];
    }
}

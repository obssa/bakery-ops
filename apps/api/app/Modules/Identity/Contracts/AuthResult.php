<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Models\User;

/**
 * What a successful login hands the shell: the bearer token (shown once), the user, the effective permission names
 * and the locale to render in.
 */
final readonly class AuthResult
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public User $user,
        public string $token,
        public array $permissions,
        public string $locale,
    ) {}
}

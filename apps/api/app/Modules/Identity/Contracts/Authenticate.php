<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Models\User;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Contracts\Hashing\Hasher;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * manifest identity.provides: "authenticate(phone, password) -> bearer token". Sanctum personal access tokens, not
 * SPA cookies (ADR 0007): the PWA keeps the token for offline use and sends it as Authorization: Bearer. Phone is
 * unique per branch, so a phone that exists in two branches needs branch_id. Login and logout are audited events.
 */
final class Authenticate
{
    public function __construct(
        private readonly Hasher $hasher,
        private readonly AuditedAction $audit,
        private readonly ActorContext $actor,
        private readonly CurrentBranch $branch,
    ) {}

    /**
     * @throws IdentityError identity.login.invalid (401), identity.branch.required (422)
     */
    public function attempt(string $phone, string $password, ?int $branchId = null): AuthResult
    {
        $matches = User::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('phone', $phone)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('branch_id')
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            throw IdentityError::branchRequired();
        }

        $user = $matches->first();
        if ($user === null || ! $this->hasher->check($password, $user->password) || ! $user->is_active) {
            throw IdentityError::invalidCredentials();
        }

        $token = $user->createToken($this->tokenName())->plainTextToken;

        $this->branch->set($user->branch);
        $this->actor->authenticate($user->id);
        $this->audit->recordEvent('auth.login', $user->getMorphClass(), $user->id, ['phone' => $user->phone]);

        return new AuthResult($user, $token, $this->permissionsOf($user), $user->locale);
    }

    /** Revoke the token this request came with (a transient test token has nothing to revoke) and record it. */
    public function logout(User $user): void
    {
        /** @var HasAbilities|null $token a TransientToken under Sanctum::actingAs */
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
        $this->audit->recordEvent('auth.logout', $user->getMorphClass(), $user->id, []);
    }

    /**
     * Every permission the user holds, through roles or directly, sorted.
     *
     * @return list<string>
     */
    public function permissionsOf(User $user): array
    {
        /** @var list<string> $names */
        $names = $user->getAllPermissions()->pluck('name')->map(fn ($n): string => (string) $n)->sort()->values()->all();

        return $names;
    }

    private function tokenName(): string
    {
        $name = config('bakery.identity.token_name');

        return is_string($name) && $name !== '' ? $name : 'api';
    }
}

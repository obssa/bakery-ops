<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\AttemptCounter;
use App\Shared\Tenancy\BranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Collection;

/**
 * manifest identity.provides: "verifyShortCode(branch, code) -> User; argon2id; 5 attempts then 15-min lockout".
 * A short code is 4-6 digits, unique per branch, stored as an argon2id hash and never shown after creation; set and
 * reset are audited user writes with the hash redacted (Audit Snapshot). Verification never says which part was
 * wrong. Failed attempts count against the entry point (branch + device fingerprint, AttemptCounter) and, when the
 * pad names the user, against that user (short_code_attempts, pin_locked_until); either lock lasts lock_minutes.
 */
final class ShortCodes
{
    private readonly AttemptCounter $attempts;

    private readonly int $minDigits;

    private readonly int $maxDigits;

    private readonly int $maxAttempts;

    private readonly int $lockMinutes;

    public function __construct(
        private readonly AuditedAction $audit,
        private readonly HashManager $hashManager,
        Cache $cache,
    ) {
        $settings = config('bakery.identity.short_code');
        $settings = is_array($settings) ? $settings : [];
        $this->minDigits = self::intSetting($settings, 'min_digits', 4);
        $this->maxDigits = self::intSetting($settings, 'max_digits', 6);
        $this->maxAttempts = self::intSetting($settings, 'max_attempts', 5);
        $this->lockMinutes = self::intSetting($settings, 'lock_minutes', 15);
        $this->attempts = new AttemptCounter($cache, $this->maxAttempts, $this->lockMinutes);
    }

    /** Store $code for $user (admin typed it or the user chose it): format, uniqueness in the branch, audit. */
    public function set(User $user, string $code): void
    {
        $this->assertFormat($code);
        $this->assertAvailable($user->branch_id, $code, $user->id);

        $this->audit->run(function (User $u) use ($code): User {
            $u->forceFill(['short_code_hash' => $this->hasher()->make($code), 'short_code_attempts' => 0, 'pin_locked_until' => null])->save();

            return $u;
        }, $user, 'user.short_code.set');
    }

    /** Replace the user's code with a fresh random one and return it, the only time it is ever visible. */
    public function reset(User $user): string
    {
        $code = $this->generate($user->branch_id, $user->id);

        $this->audit->run(function (User $u) use ($code): User {
            $u->forceFill(['short_code_hash' => $this->hasher()->make($code), 'short_code_attempts' => 0, 'pin_locked_until' => null])->save();

            return $u;
        }, $user, 'user.short_code.reset');

        return $code;
    }

    /** Throws identity.short_code.taken when another user of the branch already holds $code. */
    public function assertAvailable(int $branchId, string $code, ?int $exceptUserId = null): void
    {
        foreach ($this->candidates($branchId) as $other) {
            if ($other->id !== $exceptUserId && $this->matches($other, $code)) {
                throw IdentityError::shortCodeTaken();
            }
        }
    }

    public function assertFormat(string $code): void
    {
        if (preg_match('/^\d{'.$this->minDigits.','.$this->maxDigits.'}$/', $code) !== 1) {
            throw IdentityError::shortCodeFormat();
        }
    }

    /**
     * The user of $branchId whose code is $code. $entryPoint names where the code was typed (a device fingerprint,
     * an IP) for the lockout; $userId, when the pad named the person, moves the lockout onto that user's row.
     *
     * @throws IdentityError identity.short_code.invalid (401) or identity.short_code.locked (423)
     */
    public function verify(int $branchId, string $code, string $entryPoint, ?int $userId = null): User
    {
        $key = "short-code:{$branchId}:{$entryPoint}";
        if (($remaining = $this->attempts->lockedFor($key)) > 0) {
            throw IdentityError::shortCodeLocked($remaining);
        }

        if ($userId !== null) {
            return $this->verifyNamed($branchId, $code, $key, $userId);
        }

        if (preg_match('/^\d{'.$this->minDigits.','.$this->maxDigits.'}$/', $code) === 1) {
            foreach ($this->candidates($branchId) as $candidate) {
                if (! $this->matches($candidate, $code)) {
                    continue;
                }
                if ($candidate->isPinLocked()) {
                    throw IdentityError::shortCodeLocked($candidate->pinLockRemainingSeconds());
                }
                $this->attempts->clear($key);
                $this->resetUserAttempts($candidate);

                return $candidate;
            }
        }

        $this->attempts->fail($key);

        throw IdentityError::shortCodeInvalid();
    }

    /**
     * The users of $branchId that can be matched at all: active, not deleted, holding a code.
     *
     * @return Collection<int, User>
     */
    public function candidates(int $branchId): Collection
    {
        return User::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->whereNotNull('short_code_hash')
            ->orderBy('id')
            ->get();
    }

    public function matches(User $user, string $code): bool
    {
        return $user->short_code_hash !== null && $this->hasher()->check($code, $user->short_code_hash);
    }

    private function verifyNamed(int $branchId, string $code, string $key, int $userId): User
    {
        $user = User::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->whereKey($userId)
            ->first();

        if ($user === null || ! $user->is_active || ! $user->hasShortCode()) {
            $this->attempts->fail($key);

            throw IdentityError::shortCodeInvalid();
        }
        if ($user->isPinLocked()) {
            throw IdentityError::shortCodeLocked($user->pinLockRemainingSeconds());
        }
        if ($this->matches($user, $code)) {
            $this->attempts->clear($key);
            $this->resetUserAttempts($user);

            return $user;
        }

        $failures = $user->short_code_attempts + 1;
        $user->forceFill([
            'short_code_attempts' => $failures,
            'pin_locked_until' => $failures >= $this->maxAttempts ? CarbonImmutable::now()->addMinutes($this->lockMinutes) : $user->pin_locked_until,
        ])->saveQuietly();
        $this->attempts->fail($key);

        throw IdentityError::shortCodeInvalid();
    }

    private function resetUserAttempts(User $user): void
    {
        if ($user->short_code_attempts === 0 && $user->pin_locked_until === null) {
            return;
        }
        $user->forceFill(['short_code_attempts' => 0, 'pin_locked_until' => null])->saveQuietly();
    }

    /** A random code of min_digits that nobody in the branch holds, the user's own current code included (the space is small, so collisions are retried). */
    private function generate(int $branchId, int $forUserId): string
    {
        $others = $this->candidates($branchId);
        $max = (10 ** $this->minDigits) - 1;
        for ($try = 0; $try < 200; $try++) {
            $code = str_pad((string) random_int(0, $max), $this->minDigits, '0', STR_PAD_LEFT);
            $taken = $others->contains(fn (User $u): bool => $this->matches($u, $code));
            if (! $taken) {
                return $code;
            }
        }

        throw IdentityError::shortCodeTaken();
    }

    /** argon2id, as architecture.md section 10 requires for short codes; parameters from config/hashing.php (argon). */
    private function hasher(): Hasher
    {
        return $this->hashManager->driver('argon2id');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private static function intSetting(array $settings, string $key, int $default): int
    {
        $value = $settings[$key] ?? null;

        return is_int($value) && $value > 0 ? $value : $default;
    }
}

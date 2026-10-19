<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\AttemptCounter;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/**
 * manifest identity.provides: "can(user, permission, ?station)" and "verifyPinFor(permission, pin) -> User".
 * can() is the role defaults plus per-user direct grants (spatie), and with a station: the permission plus an
 * assignment there, unless the user holds `stations.all` (admin, manager). verifyPinFor() is the manager-PIN prompt
 * for voids and refunds: the PIN is the approver's short code, matched only against active users of the current
 * branch who hold the permission; wrong PINs lock the prompt like a pad (AttemptCounter keyed by the asking actor).
 */
final class Access
{
    private readonly AttemptCounter $attempts;

    public function __construct(
        private readonly Stations $stations,
        private readonly ShortCodes $shortCodes,
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
        private readonly Application $app,
        Cache $cache,
    ) {
        $settings = config('bakery.identity.short_code');
        $settings = is_array($settings) ? $settings : [];
        $max = $settings['max_attempts'] ?? 5;
        $lock = $settings['lock_minutes'] ?? 15;
        $this->attempts = new AttemptCounter($cache, is_int($max) && $max > 0 ? $max : 5, is_int($lock) && $lock > 0 ? $lock : 15);
    }

    public function can(User $user, string $permission, ?Station $station = null): bool
    {
        if (! $user->is_active || $user->trashed()) {
            return false;
        }
        if (! $user->can($permission)) {
            return false;
        }
        if ($station === null || $user->can('stations.all')) {
            return true;
        }

        return $this->stations->isAssigned($user, $station);
    }

    /**
     * @throws IdentityError identity.pin.rejected (403) or identity.short_code.locked (423)
     */
    public function verifyPinFor(string $permission, string $pin): User
    {
        $branchId = $this->branch->id();
        $key = 'pin:'.$branchId.':'.$this->entryPoint();
        if (($remaining = $this->attempts->lockedFor($key)) > 0) {
            throw IdentityError::shortCodeLocked($remaining);
        }

        foreach ($this->shortCodes->candidates($branchId) as $candidate) {
            if (! $this->can($candidate, $permission) || ! $this->shortCodes->matches($candidate, $pin)) {
                continue;
            }
            if ($candidate->isPinLocked()) {
                throw IdentityError::shortCodeLocked($candidate->pinLockRemainingSeconds());
            }
            $this->attempts->clear($key);

            return $candidate;
        }

        $this->attempts->fail($key);

        throw IdentityError::pinRejected();
    }

    /** Who is asking for the PIN: the logged-in user, else the client address, else the console. */
    private function entryPoint(): string
    {
        $userId = $this->actor->userId();
        if ($userId !== null) {
            return 'user:'.$userId;
        }
        if ($this->app->bound('request')) {
            $ip = $this->app->make(Request::class)->ip();
            if (is_string($ip) && $ip !== '') {
                return 'ip:'.$ip;
            }
        }

        return 'console';
    }
}

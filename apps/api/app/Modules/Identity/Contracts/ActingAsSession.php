<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Models\ActingSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Shared\Tenancy\BranchScope;
use Carbon\CarbonImmutable;

/**
 * manifest identity.provides: "ActingAsSession::start(user, 10 min)". A verified short code opens a session; the pad
 * sends its id in X-Acting-Session and ResolveActor attaches it to the request, so every write stamps
 * acting_user_id (rule 4). Bound scoped(): current() is per request and Octane forgets it (traps.md).
 */
final class ActingAsSession
{
    private ?ActingSession $current = null;

    public function start(User $user, ?Device $device = null): ActingSession
    {
        $now = CarbonImmutable::now();
        $session = new ActingSession;
        $session->forceFill([
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'device_id' => $device?->id,
            'started_at' => $now,
            'expires_at' => $now->addMinutes($this->minutes()),
            'ended_at' => null,
        ])->save();

        return $this->current = $session;
    }

    /** The session this request runs under (attached by ResolveActor or just started), if any. */
    public function current(): ?ActingSession
    {
        if ($this->current !== null && ! $this->current->isActive()) {
            $this->current = null;
        }

        return $this->current;
    }

    public function attach(ActingSession $session): void
    {
        $this->current = $session;
    }

    /** An active session by id, whatever branch it belongs to: the caller compares branches and devices. */
    public function find(int $id): ?ActingSession
    {
        $session = ActingSession::query()->withoutGlobalScope(BranchScope::class)->find($id);

        return $session !== null && $session->isActive() ? $session : null;
    }

    public function end(ActingSession $session): void
    {
        if ($session->ended_at === null) {
            $session->forceFill(['ended_at' => CarbonImmutable::now()])->save();
        }
        if ($this->current?->is($session) === true) {
            $this->current = null;
        }
    }

    public function minutes(): int
    {
        $minutes = config('bakery.identity.acting_session_minutes');

        return is_int($minutes) && $minutes > 0 ? $minutes : 10;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Contracts\ActingAsSession;
use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Shared\Actor\ActorContext;
use App\Shared\Shifts\OpenShifts;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Tenancy\CurrentBranch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fills the request-scoped CurrentBranch and ActorContext from the authenticated user (rule 4, rule 8), the
 * X-Device-Id header (an enrolled, unrevoked device of the user's branch; unknown fingerprints are ignored until
 * Phase 5 enforces enrolment) and the X-Acting-Session header (an active session of the same branch and device,
 * else 401 so the pad locks again), and (Phase 3) the station the request works at (Stations::workStationFor: the
 * X-Station-Id header when the user may work there, else their only assignment) and the shift running there (Shared's
 * OpenShifts::stampFor; null under the default binding, so nothing changes until Shifts exists). Runs after auth:sanctum and before route model binding and the idempotency
 * middleware, both of which need the branch resolved.
 */
final class ResolveActor
{
    public const string DEVICE_HEADER = 'X-Device-Id';

    public const string ACTING_SESSION_HEADER = 'X-Acting-Session';

    public const string STATION_HEADER = 'X-Station-Id';

    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
        private readonly ActingAsSession $sessions,
        private readonly Stations $stations,
        private readonly OpenShifts $shifts,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }
        if (! $user->is_active) {
            throw IdentityError::userInactive();
        }

        $this->branch->set($user->branch);
        $this->actor->authenticate($user->id);

        $device = self::deviceFor($request, $user->branch_id);
        $this->actor->onDevice($device?->id);

        $sessionId = trim((string) $request->header(self::ACTING_SESSION_HEADER, ''));
        if ($sessionId !== '') {
            $session = ctype_digit($sessionId) ? $this->sessions->find((int) $sessionId) : null;
            if ($session === null || $session->branch_id !== $user->branch_id) {
                throw IdentityError::actingSessionExpired();
            }
            if ($device !== null && $session->device_id !== null && $session->device_id !== $device->id) {
                throw IdentityError::actingSessionExpired();
            }
            $this->sessions->attach($session);
            $this->actor->actingAs($session->user_id);
        }

        $header = trim((string) $request->header(self::STATION_HEADER, ''));
        $station = $this->stations->workStationFor($user, ctype_digit($header) ? (int) $header : null);
        if ($station !== null) {
            $this->actor->atStation($station->id)->inShift($this->shifts->stampFor($station->id));
        }

        return $next($request);
    }

    /**
     * The device the header names, when it is enrolled in $branchId and not revoked. Shared with the public
     * short-code route, which has no user and resolves the branch from the device instead.
     *
     * @throws IdentityError identity.device.revoked (403)
     */
    public static function deviceFor(Request $request, ?int $branchId): ?Device
    {
        $fingerprint = trim((string) $request->header(self::DEVICE_HEADER, ''));
        if ($fingerprint === '') {
            return null;
        }
        $device = Device::query()->withoutGlobalScope(BranchScope::class)->where('fingerprint', $fingerprint)->first();
        if ($device === null || ($branchId !== null && $device->branch_id !== $branchId)) {
            return null;
        }
        if ($device->isRevoked()) {
            throw IdentityError::deviceRevoked();
        }

        return $device;
    }
}

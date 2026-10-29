<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DeviceLastSeen;
use App\Shared\Tenancy\BranchScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `device` middleware (step 5.1a, phase-5 plan 4.1, ADR 0017): on every station route (tests/Support/station_routes.php), right
 * after `actor` and before the permission check, so an unenrolled tablet is told so whoever signed in on it. A no-op while
 * identity.require_enrolled_device is off (read from the config on every request, so the kill switch needs no restart). On:
 *   - no X-Device-Id, or one no device has (a deleted device included): 403 identity.device.unknown;
 *   - a device of another branch than the signed-in user's: 403 identity.device.other_branch;
 *   - a revoked device: 403 identity.device.revoked (the actor middleware already refuses one of the user's own branch);
 *   - otherwise the request goes on, and the device's last_seen_at moves at most once a minute (DeviceLastSeen).
 * On the pad's lock screen (POST /short-code/verify) nobody is signed in yet: the device gives the branch, so only "unknown" and
 * "revoked" apply there.
 */
final class EnsureEnrolledDevice
{
    public function __construct(private readonly DeviceLastSeen $seen) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('identity.require_enrolled_device', false)) {
            return $next($request);
        }

        $fingerprint = trim((string) $request->header(ResolveActor::DEVICE_HEADER, ''));
        $device = $fingerprint === ''
            ? null
            : Device::query()->withoutGlobalScope(BranchScope::class)->where('fingerprint', $fingerprint)->first();
        if ($device === null) {
            throw IdentityError::deviceUnknown();
        }

        $user = $request->user();
        if ($user instanceof User && $device->branch_id !== $user->branch_id) {
            throw IdentityError::deviceOtherBranch();
        }
        if ($device->isRevoked()) {
            throw IdentityError::deviceRevoked();
        }

        $this->seen->touch($device);

        return $next($request);
    }
}

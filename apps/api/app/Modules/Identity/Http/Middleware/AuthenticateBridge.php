<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Contracts\BridgeDevice;
use App\Modules\Identity\Contracts\Bridges;
use App\Modules\Identity\Enums\DeviceKind;
use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Support\DeviceLastSeen;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Tenancy\CurrentBranch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `bridge` middleware (step 5.3a, phase-5 plan 4.3; ADR 0017 and ADR 0019): the print bridge's routes, which a shop computer calls, not
 * a person. `Authorization: Bridge <token>` must carry the token POST /devices gave a device of kind bridge (only its sha256 is stored, so
 * the lookup is by hash): no such header, a bearer token, a token no bridge has, or a deleted bridge's, is 401 identity.bridge.unknown; a
 * revoked bridge's is 401 identity.bridge.revoked. Then the bridge's branch is the current branch (every read and write below is scoped to
 * it), the device is on the actor stamp (no user: an action restores the person who asked for the print, ADR 0011), the bridge is left on
 * the request for Bridges::current, and its last_seen_at moves at most once a minute (DeviceLastSeen), which is how POST
 * /orders/{order}/print knows a bridge is there. No session, no cookie, nothing else is written.
 */
final class AuthenticateBridge
{
    public const string SCHEME = 'Bridge';

    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
        private readonly DeviceLastSeen $seen,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = trim((string) $request->header('Authorization', ''));
        if (preg_match('/\A'.self::SCHEME.'\s+([A-Za-z0-9]{16,128})\z/', $header, $match) !== 1) {
            throw IdentityError::bridgeUnknown();
        }

        $device = Device::query()->withoutGlobalScope(BranchScope::class)
            ->where('kind', DeviceKind::Bridge->value)
            ->where('token_hash', hash('sha256', $match[1]))
            ->first();
        if (! $device instanceof Device) {
            throw IdentityError::bridgeUnknown();
        }
        if ($device->isRevoked()) {
            throw IdentityError::bridgeRevoked();
        }
        $branch = Branch::query()->find($device->branch_id);
        if (! $branch instanceof Branch) {
            throw IdentityError::bridgeUnknown();
        }

        $this->branch->set($branch);
        $this->actor->onDevice($device->id);
        $request->attributes->set(Bridges::ATTRIBUTE, new BridgeDevice($device->id, $device->branch_id, $device->name));
        $this->seen->touch($device);

        return $next($request);
    }
}

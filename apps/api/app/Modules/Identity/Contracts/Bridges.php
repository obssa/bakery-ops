<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Enums\DeviceKind;
use App\Modules\Identity\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use LogicException;

/**
 * The print bridges of a branch, for Payments (step 5.3a, phase-5 plan 4.3; ADR 0017 and ADR 0019). A bridge is a device of kind bridge,
 * enrolled by the admin through POST /devices, which returned its token once and stored only the token's sha256 (step 5.1a).
 *   current: the bridge the `bridge` middleware (AuthenticateBridge) signed in on this request: its device id, branch and name;
 *   seenWithin: whether a bridge of the current branch that is not revoked was seen in the last $seconds (its last_seen_at, which the
 *     middleware moves at most once a minute), so a print request can tell the till at once that nothing would print.
 * Reads only. A final class resolved per use: it carries nothing of its own (traps.md, Octane).
 */
final class Bridges
{
    /** The request attribute AuthenticateBridge leaves the signed-in bridge in. */
    public const string ATTRIBUTE = 'identity.bridge';

    public function current(Request $request): BridgeDevice
    {
        $bridge = $request->attributes->get(self::ATTRIBUTE);

        return $bridge instanceof BridgeDevice ? $bridge : throw new LogicException('No print bridge signed in on this request: the route needs the `bridge` middleware.');
    }

    public function seenWithin(int $seconds): bool
    {
        return Device::query()
            ->where('kind', DeviceKind::Bridge->value)
            ->whereNull('revoked_at')
            ->where('last_seen_at', '>=', CarbonImmutable::now()->subSeconds(max(0, $seconds)))
            ->exists();
    }
}

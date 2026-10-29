<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An admin revoked a device (step 5.1a, phase-5 plan section 5; ADR 0017): from now on every request that names it is refused, and its id
 * is never enrolled again. Dispatched by Identity's POST /devices/{id}/revoke after its transaction commits, once: revoking a revoked
 * device writes nothing and announces nothing. No listener yet (the System page of step 5.5c reads the table).
 */
final class DeviceRevoked extends DomainEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        int $branchId,
        public readonly int $deviceId,
        public readonly string $deviceName,
        public readonly string $kind,
        public readonly int $byUserId,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array{device_id: int, name: string, kind: string, by_user_id: int}
     */
    public function payload(): array
    {
        return [
            'device_id' => $this->deviceId,
            'name' => $this->deviceName,
            'kind' => $this->kind,
            'by_user_id' => $this->byUserId,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An admin enrolled a device (step 5.1a, phase-5 plan section 5; ADR 0017): a tablet, a till, a KDS screen or a print bridge. Dispatched by
 * Identity's POST /devices after its transaction commits; an answer for a client_uuid already used enrols nothing and announces nothing.
 * No listener yet: the System page (step 5.5c) counts the devices from the table. Never carries a bridge's token or its hash.
 */
final class DeviceEnrolled extends DomainEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        int $branchId,
        public readonly int $deviceId,
        public readonly string $deviceName,
        public readonly string $kind,
        public readonly ?int $stationId,
        public readonly int $byUserId,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array{device_id: int, name: string, kind: string, station_id: int|null, by_user_id: int}
     */
    public function payload(): array
    {
        return [
            'device_id' => $this->deviceId,
            'name' => $this->deviceName,
            'kind' => $this->kind,
            'station_id' => $this->stationId,
            'by_user_id' => $this->byUserId,
        ];
    }
}

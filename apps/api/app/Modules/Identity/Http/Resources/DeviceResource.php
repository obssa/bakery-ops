<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A device as the admin sees it. Since step 5.1a: kind, home station, last seen, who revoked it. A bridge's token never appears here
 * (POST /devices adds it to the one answer that made it); its hash never appears anywhere.
 *
 * @mixin Device
 */
final class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Device $device */
        $device = $this->resource;

        return [
            'id' => $device->id,
            'branch_id' => $device->branch_id,
            'name' => $device->name,
            'kind' => $device->kind->value,
            'station_id' => $device->station_id,
            'fingerprint' => $device->fingerprint,
            'enrolled_by' => $device->enrolled_by,
            'enrolled_at' => $device->enrolled_at->toIso8601String(),
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'revoked_at' => $device->revoked_at?->toIso8601String(),
            'revoked_by' => $device->revoked_by,
        ];
    }
}

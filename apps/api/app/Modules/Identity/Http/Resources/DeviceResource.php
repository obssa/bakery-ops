<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
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
            'fingerprint' => $device->fingerprint,
            'enrolled_by' => $device->enrolled_by,
            'enrolled_at' => $device->enrolled_at->toIso8601String(),
            'revoked_at' => $device->revoked_at?->toIso8601String(),
        ];
    }
}

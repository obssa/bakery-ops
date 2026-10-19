<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Http\Requests\StoreDeviceRequest;
use App\Modules\Identity\Http\Resources\DeviceResource;
use App\Modules\Identity\Models\Device;
use App\Shared\Actor\ActorContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Devices of the admin's branch: enrol by fingerprint, revoke. Revoking an already revoked device changes nothing
 * and writes nothing.
 */
final class DevicesController
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ActorContext $actor,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return DeviceResource::collection(Device::query()->orderBy('name')->orderBy('id')->get());
    }

    public function store(StoreDeviceRequest $request): JsonResponse
    {
        $device = $this->audit->run(
            fn (): Device => Device::query()->create([
                'name' => $request->name(),
                'fingerprint' => $request->fingerprint(),
                'enrolled_by' => $this->actor->requireUserId(),
                'enrolled_at' => CarbonImmutable::now(),
            ]),
            null,
            'device.enrol',
        );

        return response()->json((new DeviceResource($device))->resolve($request), 201);
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $device = Device::query()->findOrFail($id);

        if (! $device->isRevoked()) {
            $this->audit->run(function (Device $d): Device {
                $d->forceFill(['revoked_at' => CarbonImmutable::now()])->save();

                return $d;
            }, $device, 'device.revoke');
        }

        return response()->json((new DeviceResource($device->fresh() ?? $device))->resolve($request));
    }
}

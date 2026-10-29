<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Http\Middleware\ResolveActor;
use App\Modules\Identity\Http\Requests\ListDevicesRequest;
use App\Modules\Identity\Http\Requests\RenameDeviceRequest;
use App\Modules\Identity\Http\Requests\StoreDeviceRequest;
use App\Modules\Identity\Http\Resources\DeviceResource;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\EnrolDevice;
use App\Shared\Actor\ActorContext;
use App\Shared\Events\DeviceRevoked;
use App\Shared\Tenancy\BranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Devices of the admin's branch (devices.manage since step 5.1a, people.manage before): list, enrol by fingerprint and kind, rename,
 * revoke. Revoking an already revoked device changes nothing, writes nothing and announces nothing; a revoked device is never renamed
 * (409) or enrolled again (its tablet makes a new id, ADR 0017). GET /devices/this (devices.self, every role) answers whether the device
 * that asks is enrolled, never with a 403 for it: it is how the "not enrolled" screen knows.
 */
final class DevicesController
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ActorContext $actor,
    ) {}

    public function index(ListDevicesRequest $request): AnonymousResourceCollection
    {
        $query = Device::query()->orderBy('name')->orderBy('id');
        if ($request->kind() !== null) {
            $query->where('kind', $request->kind()->value);
        }
        if ($request->revoked() !== null) {
            $request->revoked() ? $query->whereNotNull('revoked_at') : $query->whereNull('revoked_at');
        }

        return DeviceResource::collection($query->get());
    }

    /**
     * 201 the device it enrolled (with `token`, shown this once, for a bridge); 200 the device a client_uuid already made.
     */
    public function store(StoreDeviceRequest $request, EnrolDevice $enrol): JsonResponse
    {
        [$device, $created, $token] = $enrol->handle(
            $request->clientUuid(),
            $request->name(),
            $request->fingerprint(),
            $request->kind(),
            $request->stationId(),
        );

        $body = (new DeviceResource($device))->resolve($request);
        if ($token !== null) {
            $body['token'] = $token;
        }

        return response()->json($body, $created ? 201 : 200);
    }

    public function rename(RenameDeviceRequest $request, int $id): JsonResponse
    {
        $device = Device::query()->findOrFail($id);
        if ($device->isRevoked()) {
            throw IdentityError::deviceRevokedConflict();
        }

        $changes = ['name' => $request->name()] + ($request->movesStation() ? ['station_id' => $request->stationId()] : []);
        $device = $this->audit->run(function (Device $d) use ($changes): Device {
            $d->forceFill($changes)->save();

            return $d;
        }, $device, 'device.rename');

        return response()->json((new DeviceResource($device->fresh() ?? $device))->resolve($request));
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $device = Device::query()->findOrFail($id);

        if (! $device->isRevoked()) {
            $userId = $this->actor->requireUserId();
            $this->audit->run(function (Device $d) use ($userId): Device {
                $d->forceFill(['revoked_at' => CarbonImmutable::now(), 'revoked_by' => $userId])->save();

                return $d;
            }, $device, 'device.revoke');
            event(new DeviceRevoked($device->branch_id, $device->id, $device->name, $device->kind->value, $userId));
        }

        return response()->json((new DeviceResource($device->fresh() ?? $device))->resolve($request));
    }

    /**
     * GET /devices/this: {enrolled, revoked, device: {id, name, kind}|null, enforcement}. A device of another branch is not this branch's:
     * it reads as unknown.
     */
    public function current(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $fingerprint = trim((string) $request->header(ResolveActor::DEVICE_HEADER, ''));
        $device = $fingerprint === ''
            ? null
            : Device::query()->withoutGlobalScope(BranchScope::class)->where('fingerprint', $fingerprint)->where('branch_id', $user->branch_id)->first();

        return response()->json([
            'enrolled' => $device !== null && ! $device->isRevoked(),
            'revoked' => $device !== null && $device->isRevoked(),
            'device' => $device === null ? null : ['id' => $device->id, 'name' => $device->name, 'kind' => $device->kind->value],
            'enforcement' => (bool) config('identity.require_enrolled_device', false),
        ]);
    }
}

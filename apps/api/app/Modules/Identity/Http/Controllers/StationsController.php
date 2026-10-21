<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Http\Requests\StoreStationRequest;
use App\Modules\Identity\Http\Requests\UpdateStationRequest;
use App\Modules\Identity\Http\Resources\StationResource;
use App\Shared\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Stations of the admin branch (Shared table, managed from People): list, create and update, audited.
 */
final class StationsController
{
    public function __construct(private readonly AuditedAction $audit) {}

    public function index(): AnonymousResourceCollection
    {
        return StationResource::collection(Station::query()->orderBy('sort')->orderBy('id')->get());
    }

    public function store(StoreStationRequest $request): JsonResponse
    {
        $station = $this->audit->run(
            fn (): Station => Station::query()->create($request->stationAttributes()),
            null,
            'station.create',
        );

        return response()->json((new StationResource($station))->resolve($request), 201);
    }

    /**
     * PATCH /stations/{station}: names, requires_overseer and sort of a station of the branch (the route binding is the branch
     * scope: another branch's station, or a deleted one, is a 404). The code never changes.
     */
    public function update(UpdateStationRequest $request, int $station): JsonResponse
    {
        $model = Station::query()->findOrFail($station);

        $this->audit->run(function (Station $s) use ($request): Station {
            $s->fill($request->changes());
            $s->save();

            return $s;
        }, $model, 'station.update');

        return response()->json((new StationResource($model->fresh() ?? $model))->resolve($request));
    }
}

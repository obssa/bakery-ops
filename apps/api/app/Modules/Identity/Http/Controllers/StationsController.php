<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Http\Requests\StoreStationRequest;
use App\Modules\Identity\Http\Resources\StationResource;
use App\Shared\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Stations of the admin's branch (Shared's table, managed from People): list and create, audited.
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
}

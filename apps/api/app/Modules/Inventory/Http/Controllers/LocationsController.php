<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Inventory\Http\Requests\StoreLocationRequest;
use App\Modules\Inventory\Http\Requests\UpdateLocationRequest;
use App\Modules\Inventory\Http\Resources\LocationResource;
use App\Modules\Inventory\Models\Location;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Stock locations of the caller's branch (inventory.view to read, inventory.manage to write). Every write is one
 * AuditedAction; another branch's location is 404 through the BranchScoped route binding (rule 8).
 */
final class LocationsController
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly CurrentBranch $branch,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return LocationResource::collection(
            Location::query()->orderBy('type')->orderBy('name_en')->orderBy('id')->get(),
        );
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $location = $this->audit->run(function () use ($request): Location {
            $location = new Location;
            $location->fill($request->locationAttributes());
            $location->forceFill(['branch_id' => $this->branch->id()]);
            $location->save();

            return $location;
        }, null, 'location.create');

        return response()->json((new LocationResource($location->fresh() ?? $location))->resolve($request), 201);
    }

    public function update(UpdateLocationRequest $request, Location $location): JsonResponse
    {
        $changes = $request->changes();

        $this->audit->run(function (Location $l) use ($changes): Location {
            $l->fill($changes)->save();

            return $l;
        }, $location, 'location.update');

        return response()->json((new LocationResource($location->fresh() ?? $location))->resolve($request));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Http\Requests\StoreUnitRequest;
use App\Modules\Catalog\Http\Resources\UnitResource;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /units (catalog.view), POST /units (catalog.manage): the global unit list. Creating one is audited in the
 * admin's branch.
 */
final class UnitsController
{
    public function __construct(private readonly AuditedAction $audit) {}

    public function index(): AnonymousResourceCollection
    {
        return UnitResource::collection(Unit::query()->with('baseUnit')->orderBy('id')->get());
    }

    public function store(StoreUnitRequest $request): JsonResponse
    {
        $unit = $this->audit->run(
            fn (): Unit => Unit::query()->create($request->unitAttributes()),
            null,
            'unit.create',
        );

        return response()->json((new UnitResource($unit->fresh(['baseUnit']) ?? $unit))->resolve($request), 201);
    }
}

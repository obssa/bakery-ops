<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Inventory\Http\Requests\ListSuppliersRequest;
use App\Modules\Inventory\Http\Requests\StoreSupplierRequest;
use App\Modules\Inventory\Http\Requests\UpdateSupplierRequest;
use App\Modules\Inventory\Http\Resources\SupplierResource;
use App\Modules\Inventory\Models\Supplier;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Suppliers of the caller's branch (purchases.record to read and write). Every write is one AuditedAction behind an
 * Idempotency-Key; another branch's supplier is 404 through the BranchScoped route binding (rule 8). A supplier is switched
 * off with is_active = false; there is no delete route (the model soft-deletes for completeness).
 *
 *   GET   /suppliers?q=&active=1   200 {data: [Supplier]} by name
 *   POST  /suppliers               201 the Supplier
 *   PATCH /suppliers/{supplier}    200 the Supplier
 */
final class SuppliersController
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
    ) {}

    public function index(ListSuppliersRequest $request): AnonymousResourceCollection
    {
        $query = Supplier::query()->orderBy('name')->orderBy('id');
        if ($request->search() !== null) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $request->search()).'%';
            $query->where('name', 'like', $like);
        }
        if ($request->onlyActive()) {
            $query->where('is_active', true);
        }

        return SupplierResource::collection($query->get());
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = $this->audit->run(function () use ($request): Supplier {
            $stamp = $this->actor->stamp();
            $supplier = new Supplier;
            $supplier->fill($request->supplierAttributes());
            $supplier->forceFill([
                'branch_id' => $this->branch->id(),
                'is_active' => true,
                'created_by_user_id' => $stamp['created_by_user_id'],
                'acting_user_id' => $stamp['acting_user_id'],
                'shift_instance_id' => $stamp['shift_instance_id'],
                'actor_station_id' => $stamp['station_id'],
                'device_id' => $stamp['device_id'],
            ]);
            $supplier->save();

            return $supplier;
        }, null, 'supplier.create');

        return response()->json((new SupplierResource($supplier->fresh() ?? $supplier))->resolve($request), 201);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $changes = $request->changes();

        $this->audit->run(function (Supplier $s) use ($changes): Supplier {
            $s->fill($changes)->save();

            return $s;
        }, $supplier, 'supplier.update');

        return response()->json((new SupplierResource($supplier->fresh() ?? $supplier))->resolve($request));
    }
}

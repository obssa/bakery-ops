<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Http\Requests\ListReorderRulesRequest;
use App\Modules\Inventory\Http\Requests\StoreReorderRuleRequest;
use App\Modules\Inventory\Http\Requests\UpdateReorderRuleRequest;
use App\Modules\Inventory\Http\Resources\ReorderRuleResource;
use App\Modules\Inventory\Models\ReorderRule;
use App\Modules\Inventory\Reorder\ReorderRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Reorder rules of the caller's branch (phase-2 plan section 4, step 2.6a). Every write is one AuditedAction in
 * Reorder\ReorderRules behind an Idempotency-Key; another branch's rule, or one that was deleted, is 404 through the
 * BranchScoped route binding (rule 8).
 *
 *   GET    /reorder-rules?item_id=&location_id=   200 {data: [Rule]} oldest first (inventory.view); location_id=0: branch-wide rules
 *   POST   /reorder-rules                          201 the Rule (inventory.manage); 422 inventory.reorder.exists / role_unknown /
 *                                                  item_unknown / location_unknown
 *   PATCH  /reorder-rules/{rule}                   200 the Rule (inventory.manage)
 *   DELETE /reorder-rules/{rule}                   200 the Rule as it was (soft-deleted; its open low episode is closed)
 */
final class ReorderRulesController
{
    public function index(ListReorderRulesRequest $request): AnonymousResourceCollection
    {
        $query = ReorderRule::query()->with(['item.unit.baseUnit', 'location'])->orderBy('id');
        if ($request->itemId() !== null) {
            $query->where('item_id', $request->itemId());
        }
        if ($request->locationKey() !== null) {
            $query->where('location_key', $request->locationKey());
        }

        return ReorderRuleResource::collection($query->get());
    }

    public function store(StoreReorderRuleRequest $request, ReorderRules $rules): JsonResponse
    {
        $rule = $rules->create(
            $request->itemId(),
            $request->locationId(),
            $request->minQty(),
            $request->reorderQty(),
            $request->notifyRoles(),
            $request->isActive(),
        );

        return $this->answer($request, $rule, 201);
    }

    public function update(UpdateReorderRuleRequest $request, ReorderRule $rule, ReorderRules $rules): JsonResponse
    {
        return $this->answer($request, $rules->update($rule, $request->changes()), 200);
    }

    public function destroy(Request $request, ReorderRule $rule, ReorderRules $rules): JsonResponse
    {
        ReorderRuleResource::load($rule);

        return $this->answer($request, $rules->delete($rule), 200);
    }

    private function answer(Request $request, ReorderRule $rule, int $status): JsonResponse
    {
        return response()->json((new ReorderRuleResource(ReorderRuleResource::load($rule)))->resolve($request), $status);
    }
}

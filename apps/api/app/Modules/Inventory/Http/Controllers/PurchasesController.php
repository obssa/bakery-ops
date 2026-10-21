<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Http\Requests\CancelPurchaseRequest;
use App\Modules\Inventory\Http\Requests\ListPurchasesRequest;
use App\Modules\Inventory\Http\Requests\ReplacePurchaseLinesRequest;
use App\Modules\Inventory\Http\Requests\StorePurchaseRequest;
use App\Modules\Inventory\Http\Resources\PurchaseResource;
use App\Modules\Inventory\Models\ItemCostHistory;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\PurchaseLine;
use App\Modules\Inventory\Purchases\CancelPurchase;
use App\Modules\Inventory\Purchases\ConfirmPurchase;
use App\Modules\Inventory\Purchases\ReplaceLines;
use App\Modules\Inventory\Purchases\SaveDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Purchases (purchases.record; phase-2 plan section 4, step 2.2b). Every write is an AuditedAction in the action classes
 * behind an Idempotency-Key; another branch's purchase is 404 through the BranchScoped route binding (rule 8).
 *
 *   GET  /purchases                       200 {data: [Purchase without lines], links, meta}, newest first
 *   GET  /purchases/{purchase}            200 the Purchase with its lines
 *   POST /purchases                       201 the draft; 200 the same purchase for a client_uuid already used
 *   PUT  /purchases/{purchase}/lines      200 the draft with its new lines; 409 purchases.purchase.not_draft
 *   POST /purchases/{purchase}/confirm    200 the confirmed Purchase + costs [{item_id, cost_before, cost_after}] (a confirmed
 *                                         purchase answers itself and writes nothing); 409 purchases.purchase.cancelled;
 *                                         422 purchases.purchase.empty
 *   POST /purchases/{purchase}/cancel     200 the cancelled Purchase (a cancelled one answers itself); 409 purchases.purchase.confirmed
 * The cost fields (and `costs`) are left out for a caller without costs.view.
 */
final class PurchasesController
{
    public function index(ListPurchasesRequest $request): AnonymousResourceCollection
    {
        $query = Purchase::query()->with(['supplier', 'location', 'confirmedBy'])->orderByDesc('purchase_date')->orderByDesc('id');
        if ($request->status() !== null) {
            $query->where('status', $request->status()->value);
        }
        if ($request->supplierId() !== null) {
            $query->where('supplier_id', $request->supplierId());
        }
        if ($request->from() !== null) {
            $query->where('purchase_date', '>=', $request->from());
        }
        if ($request->to() !== null) {
            $query->where('purchase_date', '<=', $request->to());
        }

        return PurchaseResource::collection($query->paginate($request->perPage()));
    }

    public function show(Request $request, Purchase $purchase): JsonResponse
    {
        return response()->json((new PurchaseResource(PurchaseResource::load($purchase)))->resolve($request));
    }

    public function store(StorePurchaseRequest $request, SaveDraft $save): JsonResponse
    {
        [$purchase, $created] = $save->handle(
            $request->clientUuid(),
            $request->supplierId(),
            $request->invoiceNo(),
            $request->purchaseDate(),
            $request->locationId(),
            $request->notes(),
            $request->allowDuplicateInvoice(),
            $request->lines(),
        );

        return $this->answer($request, $purchase, $created ? 201 : 200);
    }

    public function replaceLines(ReplacePurchaseLinesRequest $request, Purchase $purchase, ReplaceLines $replace): JsonResponse
    {
        return $this->answer($request, $replace->handle($purchase, $request->lines()), 200);
    }

    public function confirm(Request $request, Purchase $purchase, ConfirmPurchase $confirm): JsonResponse
    {
        $purchase = $confirm->handle($purchase);

        return $this->answer($request, $purchase, 200, true);
    }

    public function cancel(CancelPurchaseRequest $request, Purchase $purchase, CancelPurchase $cancel): JsonResponse
    {
        return $this->answer($request, $cancel->handle($purchase, $request->reason()), 200);
    }

    private function answer(Request $request, Purchase $purchase, int $status, bool $withCosts = false): JsonResponse
    {
        $purchase = Purchase::query()->findOrFail($purchase->id);
        $body = (new PurchaseResource(PurchaseResource::load($purchase)))->resolve($request);
        if ($withCosts && PurchaseResource::mayViewCosts($request)) {
            $body['costs'] = $this->costs($purchase);
        }

        return response()->json($body, $status);
    }

    /**
     * What the purchase did to each item's average: the cost before its first line and after its last, read back from the
     * history rows its lines wrote (so a repeated confirm answers the same figures), ascending by item id.
     *
     * @return list<array{item_id: int, cost_before: string|null, cost_after: string}>
     */
    private function costs(Purchase $purchase): array
    {
        $lineIds = PurchaseLine::query()->where('purchase_id', $purchase->id)->pluck('id')->all();
        $byItem = [];
        foreach (ItemCostHistory::query()->whereIn('purchase_line_id', $lineIds)->orderBy('id')->get() as $row) {
            if (! isset($byItem[$row->item_id])) {
                // the first line of the item: the average it started from (null = unvalued stock)
                $byItem[$row->item_id] = ['before' => $row->cost_before?->toString(), 'after' => ''];
            }
            $byItem[$row->item_id]['after'] = $row->cost_after->toString();
        }
        ksort($byItem);

        $costs = [];
        foreach ($byItem as $itemId => $step) {
            $costs[] = ['item_id' => $itemId, 'cost_before' => $step['before'], 'cost_after' => $step['after']];
        }

        return $costs;
    }
}

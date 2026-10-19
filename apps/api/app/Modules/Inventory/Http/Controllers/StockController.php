<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Movement;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Exceptions\InventoryError;
use App\Modules\Inventory\Http\Requests\ListStockRequest;
use App\Modules\Inventory\Http\Requests\StoreMovementRequest;
use App\Modules\Inventory\Http\Resources\MovementResource;
use App\Modules\Inventory\Http\Resources\StockLine;
use App\Modules\Inventory\Http\Resources\StockLineResource;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /stock?location= (inventory.view): the balances the Ledger keeps at a location. POST /stock/movements
 * (inventory.adjust): the Phase 0 manual movements, written through the Ledger (rule 1) together with their audit row
 * in one transaction; the quantity is converted to the item's base unit (Catalog Units::toBaseOf) and waste is stored
 * as stock leaving. A client_uuid seen before replays the stored movement (ADR 0003) instead of writing a second one.
 */
final class StockController
{
    public function __construct(
        private readonly Stock $stock,
        private readonly Ledger $ledger,
        private readonly AuditedAction $audit,
        private readonly Units $units,
        private readonly ConnectionResolverInterface $db,
    ) {}

    public function index(ListStockRequest $request): AnonymousResourceCollection
    {
        $levels = $this->stock->at($request->locationId());
        $items = Item::query()->withTrashed()->whereKey(array_keys($levels))->get()->keyBy('id');

        $lines = [];
        foreach ($levels as $itemId => $level) {
            $item = $items->get($itemId);
            $lines[] = new StockLine(
                $level,
                $item instanceof Item ? $item->name_en : '',
                $item instanceof Item ? $item->name_am : '',
                $item instanceof Item ? $this->units->baseOf($item->unit_id)->code : '',
            );
        }

        return StockLineResource::collection($lines);
    }

    public function storeMovement(StoreMovementRequest $request): JsonResponse
    {
        $item = Item::query()->findOrFail($request->itemId());
        $reason = $request->reason();
        $qty = $this->units->toBaseOf($request->qty(), $request->unitId() ?? $item->unit_id, $item->unit_id, 'unit_id');
        if ($reason->direction() === 'out') {
            $qty = $qty->negated();
        }
        $clientUuid = $request->clientUuid();
        $intent = MovementIntent::of($item->id, $request->locationId(), $qty, $reason, 'manual', null, null, $clientUuid);

        $earlier = $clientUuid === null ? null : $this->stock->movementByClientUuid($clientUuid);
        if ($earlier !== null) {
            return $this->replay($request, $earlier, $intent, $item);
        }

        try {
            $movement = $this->db->connection()->transaction(function () use ($intent, $request): Movement {
                $movement = $this->ledger->append($intent);
                $this->audit->recordEvent('stock.movement.create', 'stock_movement', $movement->id, $movement->toArray() + ['note' => $request->note()]);

                return $movement;
            });
        } catch (UniqueConstraintViolationException $e) {
            // the same offline record arrived twice at once: the other request wrote it
            $earlier = $clientUuid === null ? null : $this->stock->movementByClientUuid($clientUuid);

            return $earlier === null ? throw $e : $this->replay($request, $earlier, $intent, $item);
        }

        return response()->json($this->body($request, $movement, $item), 201);
    }

    /** A client_uuid already written: the same record again is answered with it, anything else is a conflict. */
    private function replay(Request $request, Movement $earlier, MovementIntent $intent, Item $item): JsonResponse
    {
        $same = $earlier->itemId === $intent->itemId
            && $earlier->locationId === $intent->locationId
            && $earlier->reason === $intent->reason
            && $earlier->qty->equals($intent->qty);
        if (! $same) {
            throw InventoryError::clientUuidReused($earlier->id);
        }

        return response()->json($this->body($request, $earlier, $item));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request, Movement $movement, Item $item): array
    {
        $balance = $this->stock->of($movement->itemId, $movement->locationId)->toArray();

        return (new MovementResource($movement))->resolve($request) + [
            'base_unit_code' => $this->units->baseOf($item->unit_id)->code,
            'balance' => [
                'on_hand' => $balance['on_hand'],
                'reserved' => $balance['reserved'],
                'available' => $balance['available'],
            ],
        ];
    }
}

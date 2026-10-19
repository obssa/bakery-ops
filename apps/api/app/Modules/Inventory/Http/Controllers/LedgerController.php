<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Http\Requests\ListLedgerRequest;
use App\Modules\Inventory\Http\Resources\MovementResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /ledger (inventory.view): the movements of the caller's branch as written, newest first, paginated, filtered by
 * item, by location (the rows that changed its balance) and by an inclusive time window.
 */
final class LedgerController
{
    public function __construct(private readonly Stock $stock) {}

    public function index(ListLedgerRequest $request): AnonymousResourceCollection
    {
        return MovementResource::collection($this->stock->movements(
            $request->itemId(),
            $request->locationId(),
            $request->from(),
            $request->to(),
            $request->perPage(),
        ));
    }
}

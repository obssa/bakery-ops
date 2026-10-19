<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Http\Requests\StoreItemPriceRequest;
use App\Modules\Catalog\Http\Resources\ItemPriceResource;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemPrice;
use App\Modules\Catalog\Services\PriceHistory;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /items/{item}/prices: the price history, newest first. POST: a new price in the branch currency, closing the
 * open one (Services\PriceHistory), as one audited write whose subject is the new price row.
 */
final class ItemPricesController
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly PriceHistory $history,
        private readonly CurrentBranch $branch,
    ) {}

    public function index(Item $item): AnonymousResourceCollection
    {
        return ItemPriceResource::collection(
            ItemPrice::query()->where('item_id', $item->id)->orderByDesc('effective_from')->orderByDesc('id')->get(),
        );
    }

    public function store(StoreItemPriceRequest $request, Item $item): JsonResponse
    {
        $price = Money::of($request->price(), $this->branch->currency());
        $from = $request->effectiveFrom() ?? CarbonImmutable::now()->utc()->startOfSecond();

        $row = $this->audit->run(
            fn (): ItemPrice => $this->history->open($item, $price, $from),
            null,
            'item.price.set',
            ['item_id' => $item->id],
        );

        return response()->json((new ItemPriceResource($row->fresh() ?? $row))->resolve($request), 201);
    }
}

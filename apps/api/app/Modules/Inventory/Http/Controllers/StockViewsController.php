<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Exceptions\InventoryError;
use App\Modules\Inventory\Http\Requests\ListReorderListRequest;
use App\Modules\Inventory\Http\Requests\ListStockAlertsRequest;
use App\Modules\Inventory\Http\Requests\ShowStockStripRequest;
use App\Modules\Inventory\Models\StockAlertState;
use App\Modules\Inventory\Views\DisplayCasePanel;
use App\Modules\Inventory\Views\ReorderList;
use App\Modules\Inventory\Views\StockStrip;
use App\Shared\Actor\ActorContext;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The read views of low stock (phase-2 plan section 4, step 2.6b). All are inventory.view, none takes an Idempotency-Key.
 *
 *   GET /reorder-list?location_id=               200 {data: [row], generated_at}: the purchasing screen (Views\ReorderList)
 *   GET /stock-alerts?active=1&type=&limit=      200 {data: [episode]}: the admin inventory screen
 *   GET /stations/{station}/stock-strip?limit=   200 {location_id, data}, ETag / 304; 403 inventory.station.forbidden (Views\StockStrip)
 *   GET /display-case                            200 {location_id, data}, ETag / 304 (Views\DisplayCasePanel)
 */
final class StockViewsController
{
    public function __construct(private readonly Access $access, private readonly ActorContext $actor) {}

    public function reorderList(ListReorderListRequest $request, ReorderList $list): JsonResponse
    {
        return response()->json($list->build($request->locationKey()));
    }

    public function alerts(ListStockAlertsRequest $request): JsonResponse
    {
        $episodes = StockAlertState::query()
            ->with(['item.unit.baseUnit', 'location'])
            ->when($request->activeOnly(), fn ($q) => $q->where('is_active', 1))
            ->when($request->type() !== null, fn ($q) => $q->where('type', $request->type()))
            ->orderByDesc('id')
            ->limit($request->limit())
            ->get();

        return response()->json(['data' => $episodes->map(fn (StockAlertState $e): array => $this->episode($e))->all()]);
    }

    public function strip(ShowStockStripRequest $request, Station $station, StockStrip $strip): Response
    {
        $this->assertAtStation($station);
        $location = $strip->locationOf($station);
        $limit = $request->limit();

        return ConditionalPoll::respond($request, $strip->etag($station, $limit, $location), fn (): array => $strip->build($location, $limit));
    }

    public function displayCase(Request $request, DisplayCasePanel $panel): Response
    {
        $location = $panel->location();

        return ConditionalPoll::respond($request, $panel->etag($location), fn (): array => $panel->build($location));
    }

    /** The station's strip is for the people who work at it and for those who work at every station (the person on a shared screen counts). */
    private function assertAtStation(Station $station): void
    {
        $person = User::query()->find($this->actor->effectiveUserId() ?? $this->actor->requireUserId());
        if (! $person instanceof User || ! $this->access->can($person, 'inventory.view', $station)) {
            throw InventoryError::stationForbidden();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function episode(StockAlertState $episode): array
    {
        $item = $episode->item;
        $place = $episode->location;

        return [
            'id' => $episode->id,
            'type' => $episode->type,
            'item' => $item === null ? null : [
                'id' => $item->id, 'name_en' => $item->name_en, 'name_am' => $item->name_am, 'unit' => ($item->unit->baseUnit ?? $item->unit)->code,
            ],
            'location' => $place === null ? null : ['id' => $place->id, 'name_en' => $place->name_en, 'name_am' => $place->name_am, 'type' => $place->type->value],
            'active_since' => $episode->active_since->toIso8601String(),
            'cleared_at' => $episode->cleared_at?->toIso8601String(),
            'available_at_start' => $episode->available_at_start->toString(),
            'threshold_qty' => $episode->threshold_qty?->toString(),
        ];
    }
}

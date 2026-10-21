<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Alerts;

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockAlertState;
use App\Shared\Actor\ActorContext;
use App\Shared\Events\DomainEvent;
use App\Shared\Events\StockOut;
use App\Shared\Events\StockRecovered;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;

/**
 * The logic of the stock alert (phase-2 plan section 5, ADR 0011): reads the CURRENT balance of one (item, place) under
 * its row lock and opens or closes the `out` episode in stock_alert_states. An episode opens when available (on_hand -
 * reserved) is 0 or below and none is open, which dispatches StockOut; it closes when available is above 0 again, which
 * dispatches StockRecovered. The unique key of the table makes a concurrent or repeated evaluation a no-op, and only
 * the evaluation that inserted the row announces it, so the event fires once per episode however often it runs. Level
 * `low` (a reorder rule's minimum) is added at step 2.6 beside `out`.
 *
 * It runs inside EvaluateBalance, as the person whose movement caused it (Support\RestoresActor), and reads that person
 * from ActorContext only to record caused_by_user_id. Resolved per use (scoped ActorContext, traps.md).
 */
final class AlertEvaluator
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly ConnectionResolverInterface $db,
        private readonly Dispatcher $events,
        private readonly ActorContext $actor,
    ) {}

    /** The `out` decision as a pure function of the available quantity and whether an `out` episode is open. */
    public static function decideOut(Quantity $available, bool $open): AlertAction
    {
        $out = ! $available->isPositive();

        return match (true) {
            $out && ! $open => AlertAction::Open,
            ! $out && $open => AlertAction::Close,
            default => AlertAction::Nothing,
        };
    }

    /**
     * Bring the episodes of (item, location) in line with the balance, and announce what changed after the commit.
     */
    public function evaluate(int $itemId, int $locationId): void
    {
        /** @var list<DomainEvent> $announce */
        $announce = $this->connection()->transaction(function () use ($itemId, $locationId): array {
            $level = $this->ledger->lock([[$itemId, $locationId]])[Ledger::key($itemId, $locationId)];
            $open = StockAlertState::query()
                ->withoutGlobalScope(BranchScope::class)
                ->where('item_id', $itemId)
                ->where('location_key', $locationId)
                ->where('type', StockAlertState::OUT)
                ->where('is_active', 1)
                ->first();

            return match (self::decideOut($level->available(), $open !== null)) {
                AlertAction::Open => $this->open($itemId, $locationId, $level->onHand, $level->reserved, $level->available()),
                AlertAction::Close => $open === null ? [] : $this->close($open),
                AlertAction::Nothing => [],
            };
        });

        foreach ($announce as $event) {
            $this->events->dispatch($event);
        }
    }

    /**
     * @return list<DomainEvent>
     */
    private function open(int $itemId, int $locationId, Quantity $onHand, Quantity $reserved, Quantity $available): array
    {
        $item = Item::query()->withoutGlobalScope(BranchScope::class)->withTrashed()->findOrFail($itemId);
        $location = Location::query()->withoutGlobalScope(BranchScope::class)->withTrashed()->findOrFail($locationId);
        $now = CarbonImmutable::now();
        $causedBy = $this->actor->effectiveUserId();

        $inserted = StockAlertState::query()->withoutGlobalScope(BranchScope::class)->insertOrIgnore([
            'branch_id' => $item->branch_id,
            'item_id' => $itemId,
            'location_id' => $locationId,
            'location_key' => $locationId,
            'type' => StockAlertState::OUT,
            'is_active' => 1,
            'active_since' => $now->format('Y-m-d H:i:s.u'),
            'cleared_at' => null,
            'available_at_start' => $available->toString(),
            'threshold_qty' => null,
            'caused_by_user_id' => $causedBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === 0) {
            return []; // somebody else opened it first: they announce it
        }

        $alertId = StockAlertState::query()->withoutGlobalScope(BranchScope::class)
            ->where('item_id', $itemId)->where('location_key', $locationId)->where('type', StockAlertState::OUT)->where('is_active', 1)
            ->value('id');
        if (! is_numeric($alertId)) {
            throw new LogicException("The stock alert episode of item {$itemId} at location {$locationId} was inserted and cannot be read back.");
        }

        return [new StockOut(
            branchId: $item->branch_id,
            itemId: $itemId,
            locationId: $locationId,
            itemNameEn: $item->name_en,
            itemNameAm: $item->name_am,
            locationNameEn: $location->name_en,
            locationNameAm: $location->name_am,
            itemKind: $item->kind->value,
            fulfilment: $item->fulfilment->value,
            available: $available->toString(),
            onHand: $onHand->toString(),
            reserved: $reserved->toString(),
            alertId: (int) $alertId,
            causedByUserId: $causedBy,
            occurredAt: $now,
        )];
    }

    /**
     * @return list<DomainEvent>
     */
    private function close(StockAlertState $open): array
    {
        $now = CarbonImmutable::now();
        $closed = StockAlertState::query()->withoutGlobalScope(BranchScope::class)
            ->whereKey($open->id)->where('is_active', 1)
            ->update(['cleared_at' => $now->format('Y-m-d H:i:s.u'), 'is_active' => null, 'updated_at' => $now]);
        if ($closed === 0) {
            return [];
        }

        return [new StockRecovered(
            branchId: $open->branch_id,
            itemId: $open->item_id,
            locationId: (int) $open->location_id,
            type: $open->type,
            alertId: $open->id,
            occurredAt: $now,
        )];
    }

    private function connection(): Connection
    {
        $connection = $this->db->connection();
        if (! $connection instanceof Connection) {
            throw new LogicException('The alert evaluation needs a database connection.');
        }

        return $connection;
    }
}

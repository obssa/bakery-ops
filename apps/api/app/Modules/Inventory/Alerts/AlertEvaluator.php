<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Alerts;

use App\Modules\Catalog\Contracts\Routing;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\StockLevel;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\LedgerReader;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\ReorderRule;
use App\Modules\Inventory\Models\StockAlertState;
use App\Shared\Actor\ActorContext;
use App\Shared\Events\DomainEvent;
use App\Shared\Events\StockLow;
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
 * The logic of the stock alerts (phase-2 plan section 5, ADR 0011): reads the CURRENT balance of one (item, place) under its
 * row lock and opens or closes its episodes in stock_alert_states. Two levels, each with its own episode:
 *   - `out`: available (on_hand - reserved) is 0 or below. Opens StockOut, closes (StockRecovered, type out) when available is above 0.
 *   - `low`: a live, active reorder rule for the item says available is at or below its minimum. Opens StockLow, closes
 *     (StockRecovered, type low) when available is above the minimum, or the rule is gone or switched off. No hysteresis (the
 *     plan default, open question 3): every crossing of the minimum is an episode. The rule is read at every evaluation, so a
 *     changed minimum moves the episode and the rule as it then stands is what StockLow carries.
 * A rule for the place and a branch-wide rule (no place: the sum of `available` over the branch's stock places, never the cash
 * drawer) are two keys, 0 being the branch-wide one, and every evaluation of a balance looks at both. `low` and `out` of one
 * balance are independent: going from low to out opens `out` and leaves `low` open, and both close on the way up.
 * The unique key of the table makes a concurrent or repeated evaluation a no-op, and only the evaluation that inserted the row
 * announces it, so each event fires once per episode however often it runs. The row is claimed with an INSERT, never a
 * SELECT ... FOR UPDATE of a row that may not exist (traps.md, "First-row race"): the only row locked is the balance, which exists.
 *
 * It runs inside EvaluateBalance, as the person whose movement caused it (Support\RestoresActor), and reads that person
 * from ActorContext only to record caused_by_user_id. Resolved per use (scoped ActorContext, traps.md).
 */
final class AlertEvaluator
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly LedgerReader $reader,
        private readonly ConnectionResolverInterface $db,
        private readonly Dispatcher $events,
        private readonly ActorContext $actor,
        private readonly Routing $routing,
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
     * The `low` decision as a pure function of the available quantity, the minimum of the live rule (null when there is no
     * live, active rule) and whether a `low` episode is open. At the minimum is low; above it is not; no rule closes an open one.
     */
    public static function decideLow(Quantity $available, ?Quantity $min, bool $open): AlertAction
    {
        $low = $min !== null && $available->isLessThanOrEqualTo($min);

        return match (true) {
            $low && ! $open => AlertAction::Open,
            ! $low && $open => AlertAction::Close,
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

            return [
                ...$this->evaluateOut($itemId, $locationId, $level),
                ...$this->evaluateLow($itemId, $locationId, $locationId, $level->available(), $level),
                ...$this->evaluateLow($itemId, $locationId, 0, $this->branchAvailable($itemId, $level), $level),
            ];
        });

        foreach ($announce as $event) {
            $this->events->dispatch($event);
        }
    }

    /**
     * @return list<DomainEvent>
     */
    private function evaluateOut(int $itemId, int $locationId, StockLevel $level): array
    {
        $open = $this->openEpisode($itemId, $locationId, StockAlertState::OUT);

        return match (self::decideOut($level->available(), $open !== null)) {
            AlertAction::Open => $this->openOut($itemId, $locationId, $level),
            AlertAction::Close => $open === null ? [] : $this->close($open),
            AlertAction::Nothing => [],
        };
    }

    /**
     * The `low` level of one rule key: the place itself (key = its id, available = its own) or the whole branch (key 0,
     * available = the sum over its stock places). $at is the balance being evaluated and $level its locked row.
     *
     * @return list<DomainEvent>
     */
    private function evaluateLow(int $itemId, int $at, int $key, Quantity $available, StockLevel $level): array
    {
        $rule = ReorderRule::query()
            ->where('item_id', $itemId)->where('location_key', $key)->where('is_active', true)->first();
        $open = $this->openEpisode($itemId, $key, StockAlertState::LOW);

        return match (self::decideLow($available, $rule?->min_qty, $open !== null)) {
            AlertAction::Open => $rule === null ? [] : $this->openLow($rule, $at, $key, $available, $level),
            AlertAction::Close => $open === null ? [] : $this->close($open),
            AlertAction::Nothing => [],
        };
    }

    /**
     * What is available across the branch's stock places: the other places as committed, this one as locked (a plain read could
     * show an older snapshot of the row this evaluation holds).
     */
    private function branchAvailable(int $itemId, StockLevel $own): Quantity
    {
        $sum = Quantity::zero();
        foreach ($this->reader->levelsOfItem($itemId) as $level) {
            $sum = $sum->plus(($level->locationId === $own->locationId ? $own : $level)->available());
        }

        return $sum;
    }

    private function openEpisode(int $itemId, int $key, string $type): ?StockAlertState
    {
        return StockAlertState::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('item_id', $itemId)
            ->where('location_key', $key)
            ->where('type', $type)
            ->where('is_active', 1)
            ->first();
    }

    /**
     * @return list<DomainEvent>
     */
    private function openOut(int $itemId, int $locationId, StockLevel $level): array
    {
        $item = Item::query()->withoutGlobalScope(BranchScope::class)->withTrashed()->findOrFail($itemId);
        $location = Location::query()->withoutGlobalScope(BranchScope::class)->withTrashed()->findOrFail($locationId);
        $causedBy = $this->actor->effectiveUserId();
        $now = CarbonImmutable::now();

        $alertId = $this->claim($item, $locationId, $locationId, StockAlertState::OUT, $level->available(), null, $causedBy, $now);
        if ($alertId === null) {
            return []; // somebody else opened it first: they announce it
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
            available: $level->available()->toString(),
            onHand: $level->onHand->toString(),
            reserved: $level->reserved->toString(),
            alertId: $alertId,
            causedByUserId: $causedBy,
            stationId: $location->station_id,
            productionStationId: $this->productionStationId($item),
            occurredAt: $now,
        )];
    }

    /**
     * @return list<DomainEvent>
     */
    private function openLow(ReorderRule $rule, int $at, int $key, Quantity $available, StockLevel $level): array
    {
        $item = Item::query()->withoutGlobalScope(BranchScope::class)->withTrashed()->with('unit.baseUnit')->findOrFail($rule->item_id);
        $place = $key === 0 ? null : Location::query()->withoutGlobalScope(BranchScope::class)->withTrashed()->findOrFail($key);
        $causedBy = $this->actor->effectiveUserId();
        $now = CarbonImmutable::now();

        $alertId = $this->claim($item, $key === 0 ? null : $key, $key, StockAlertState::LOW, $available, $rule->min_qty, $causedBy, $now);
        if ($alertId === null) {
            return [];
        }
        $unit = $item->unit->baseUnit ?? $item->unit;
        $all = $key === 0;

        return [new StockLow(
            branchId: $item->branch_id,
            itemId: $item->id,
            locationId: $key,
            itemNameEn: $item->name_en,
            itemNameAm: $item->name_am,
            locationNameEn: $place === null ? (string) trans('inventory.reorder.all_places', [], 'en') : $place->name_en,
            locationNameAm: $place === null ? (string) trans('inventory.reorder.all_places', [], 'am') : $place->name_am,
            itemKind: $item->kind->value,
            fulfilment: $item->fulfilment->value,
            available: $available->toString(),
            onHand: ($all ? $available : $level->onHand)->toString(),
            reserved: ($all ? Quantity::zero() : $level->reserved)->toString(),
            alertId: $alertId,
            causedByUserId: $causedBy,
            stationId: $place?->station_id,
            productionStationId: $this->productionStationId($item),
            minQty: $rule->min_qty->toString(),
            reorderQty: $rule->reorder_qty->toString(),
            notifyRoles: $rule->notify_roles,
            occurredAt: $now,
            unit: $unit->code,
        )];
    }

    /**
     * Claim the open episode with one INSERT; the unique key (item_id, location_key, type, is_active) picks the winner of a
     * race. Answers the episode id when this call inserted it, null when it was already open.
     */
    private function claim(Item $item, ?int $locationId, int $key, string $type, Quantity $available, ?Quantity $threshold, ?int $causedBy, CarbonImmutable $now): ?int
    {
        $inserted = StockAlertState::query()->withoutGlobalScope(BranchScope::class)->insertOrIgnore([
            'branch_id' => $item->branch_id,
            'item_id' => $item->id,
            'location_id' => $locationId,
            'location_key' => $key,
            'type' => $type,
            'is_active' => 1,
            'active_since' => $now->format('Y-m-d H:i:s.u'),
            'cleared_at' => null,
            'available_at_start' => $available->toString(),
            'threshold_qty' => $threshold?->toString(),
            'caused_by_user_id' => $causedBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === 0) {
            return null;
        }

        $alertId = StockAlertState::query()->withoutGlobalScope(BranchScope::class)
            ->where('item_id', $item->id)->where('location_key', $key)->where('type', $type)->where('is_active', 1)
            ->value('id');
        if (! is_numeric($alertId)) {
            throw new LogicException("The stock alert episode ({$type}) of item {$item->id} at place {$key} was inserted and cannot be read back.");
        }

        return (int) $alertId;
    }

    private function productionStationId(Item $item): ?int
    {
        return $item->isProduct() ? $this->routing->productionStationFor($item)?->id : null;
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

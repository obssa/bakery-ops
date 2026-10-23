<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Handover;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Identity\Contracts\People;
use App\Modules\Orders\Contracts\OpenLineView;
use App\Modules\Orders\Contracts\OpenOrderView;
use App\Modules\Shifts\Contracts\ReplayCount;
use App\Modules\Shifts\Contracts\ReplayDuty;
use App\Modules\Shifts\Contracts\ReplayPlace;
use App\Modules\Shifts\Contracts\ReplayState;
use App\Modules\Shifts\Support\ShiftPresenter;
use App\Shared\Models\Station;
use Carbon\CarbonInterface;

/**
 * GET /replay (phase-3 plan section 4): {at, station, shift: ShiftSummary or null, on_duty: [{user, joined_at, left_at}], locations: [{location,
 * balances: [{item, on_hand, reserved}], last_locked}], open_orders: [{order_id, order_no, table_code, type, sent_at, owner, lines: [{item_id,
 * qty, status}]}], counts: [{count_id, shift_id, location_id, type, status_at, submitted_at, decided_at, locked_at}]}. There is no cash block:
 * cash is not in the ledger, so a shift's money is read from GET /shifts/{shift}/summary and /cash. Resolved per use.
 */
final class ReplayPresenter
{
    public function __construct(
        private readonly People $people,
        private readonly Items $items,
        private readonly ShiftPresenter $shifts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(ReplayState $state, Station $station): array
    {
        $personIds = array_map(static fn (ReplayDuty $d): int => $d->userId, $state->onDuty);
        foreach ($state->openOrders as $order) {
            $personIds[] = $order->ownerUserId;
        }
        $names = $this->people->names($personIds);
        $person = static fn (int $id): array => ['id' => $id, 'name' => $names[$id] ?? ''];

        $itemIds = [];
        foreach ($state->places as $place) {
            foreach ($place->levels as $level) {
                $itemIds[] = $level->itemId;
            }
        }
        $summaries = $this->items->summaries($itemIds);

        return [
            'at' => $state->at->toIso8601String(),
            'station' => ShiftPresenter::station($station),
            'shift' => $state->shift === null ? null : $this->shifts->many([$state->shift])[0],
            'on_duty' => array_map(static fn (ReplayDuty $d): array => [
                'user' => $person($d->userId),
                'joined_at' => self::time($d->joinedAt),
                'left_at' => self::time($d->leftAt),
            ], $state->onDuty),
            'locations' => array_map(static fn (ReplayPlace $p): array => [
                'location' => $p->location->toArray(),
                'balances' => array_map(static fn ($level): array => [
                    'item' => [
                        'id' => $level->itemId,
                        'name_en' => ($summaries[$level->itemId] ?? null)?->nameEn ?? '',
                        'name_am' => ($summaries[$level->itemId] ?? null)?->nameAm ?? '',
                        'unit' => ($summaries[$level->itemId] ?? null)?->unit ?? '',
                    ],
                    'on_hand' => $level->onHand->toString(),
                    'reserved' => $level->reserved->toString(),
                ], $p->levels),
                'last_locked' => $p->lastLocked === null ? null : [
                    'count_id' => $p->lastLocked->countId,
                    'chain_seq' => $p->lastLocked->chainSeq,
                    'payload_hash' => $p->lastLocked->payloadHash,
                    'locked_at' => $p->lastLocked->lockedAt->format('Y-m-d\TH:i:s.uP'),
                ],
            ], $state->places),
            'open_orders' => array_map(static fn (OpenOrderView $o): array => [
                'order_id' => $o->orderId,
                'order_no' => $o->orderNo,
                'table_code' => $o->tableCode,
                'type' => $o->type,
                'sent_at' => self::time($o->sentAt),
                'owner' => $person($o->ownerUserId),
                'lines' => array_map(static fn (OpenLineView $l): array => ['item_id' => $l->itemId, 'qty' => $l->qty, 'status' => $l->status], $o->lines),
            ], $state->openOrders),
            'counts' => array_map(static fn (ReplayCount $c): array => [
                'count_id' => $c->countId,
                'shift_id' => $c->shiftId,
                'location_id' => $c->locationId,
                'type' => $c->type,
                'status_at' => $c->statusAt,
                'submitted_at' => self::time($c->submittedAt),
                'decided_at' => self::time($c->decidedAt),
                'locked_at' => self::time($c->lockedAt),
            ], $state->counts),
        ];
    }

    private static function time(?CarbonInterface $time): ?string
    {
        return $time?->toIso8601String();
    }
}

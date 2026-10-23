<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Handover;

use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\LocationView;
use App\Modules\Shifts\Cash\CashDeclarations;
use App\Modules\Shifts\Contracts\Locks;
use App\Modules\Shifts\Counts\CountPresenter;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Models\HandoverNote;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Support\ShiftPresenter;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;

/**
 * GET /stations/{station}/handover (phase-3 plan section 4): what the outgoing shift leaves for the incoming one, the Start shift screen's
 * first panel.
 *   previous:         the newest shift of the station that has handed in its closing counts (pending approval or locked), as a ShiftSummary;
 *                     the shift that runs now is not it;
 *   notes:            the previous shift's notes, and any note addressed to the person that was written at this station within
 *                     `shifts.handover_note_days` (7) days, oldest first (there is no read receipt: a note is append-only);
 *   previous_closing: one row per stock place of the station and for its cash drawer: the closing the next opening count is prefilled from.
 *                     A closing that was handed in and not locked is the newest word on the place and is `provisional` (no hash yet, because
 *                     Locks::previousClosing ignores it until it is approved); otherwise the latest locked one. The drawer's rows carry
 *                     `float_left_total`, the money that stays for the next shift (CashDeclarations::carriedFloat; for a provisional one, the
 *                     figure it proposes), null for any other place;
 *   pending_counts:   the unresolved counts (awaiting a co-signature or a decision) at those places, as CountSummary.
 * Resolved per use.
 */
final class HandoverPanel
{
    public function __construct(
        private readonly Locations $locations,
        private readonly Locks $locks,
        private readonly CashDeclarations $cash,
        private readonly CountPresenter $counts,
        private readonly ShiftPresenter $shifts,
        private readonly NotePresenter $notes,
    ) {}

    /**
     * @return array{previous: array<string, mixed>|null, notes: list<array<string, mixed>>, previous_closing: list<array<string, mixed>>, pending_counts: list<array<string, mixed>>}
     */
    public function of(Station $station, int $personId): array
    {
        $previous = ShiftInstance::query()
            ->where('station_id', $station->id)
            ->whereIn('status', [ShiftStatus::PendingApproval->value, ShiftStatus::Locked->value])
            ->orderByDesc('started_at')->orderByDesc('id')->first();

        $places = $this->locations->stockLocationsOf($station);
        $drawer = $this->locations->cashDrawerOf($station);
        if ($drawer instanceof LocationView) {
            $places[] = $drawer;
        }

        return [
            'previous' => $previous === null ? null : $this->shifts->many([$previous])[0],
            'notes' => $this->notes->many($this->noteRows($station, $previous, $personId)),
            'previous_closing' => $this->closings($places, $drawer),
            'pending_counts' => $this->counts->summaries($this->pending($places)),
        ];
    }

    /**
     * @return list<HandoverNote>
     */
    private function noteRows(Station $station, ?ShiftInstance $previous, int $personId): array
    {
        $days = config('shifts.handover_note_days', 7);
        $since = CarbonImmutable::now()->subDays(is_int($days) ? $days : 7)->format('Y-m-d H:i:s.u');
        $stationShifts = ShiftInstance::query()->where('station_id', $station->id)->select('id');

        $rows = HandoverNote::query()
            ->where(function ($w) use ($previous, $personId, $stationShifts, $since): void {
                if ($previous !== null) {
                    $w->orWhere('shift_instance_id', $previous->id);
                }
                $w->orWhere(fn ($mine) => $mine->where('to_user_id', $personId)->whereIn('shift_instance_id', $stationShifts)->where('created_at', '>=', $since));
            })
            ->orderBy('created_at')->orderBy('id')->limit(200)->get();

        return array_values($rows->all());
    }

    /**
     * @param  list<LocationView>  $places
     * @return list<array<string, mixed>>
     */
    private function closings(array $places, ?LocationView $drawer): array
    {
        $rows = [];
        foreach ($places as $place) {
            $unresolved = $this->locks->unresolvedAt($place->id);
            $provisional = $unresolved instanceof StockCount && $unresolved->type === CountType::Closing;
            $count = $provisional ? $unresolved : $this->locks->previousClosing($place->id);
            if (! $count instanceof StockCount) {
                continue;
            }
            $isDrawer = $drawer instanceof LocationView && $drawer->id === $place->id;
            if (! $isDrawer) {
                $float = null;
            } elseif ($provisional) {
                $float = $this->cash->find($count)?->float_left_total?->amount();
            } else {
                $float = $this->cash->carriedFloat($place->id)?->amount();
            }
            $rows[] = [
                'location' => $place->toArray(),
                'count_id' => $count->id,
                'chain_seq' => $count->status === CountStatus::Locked ? $count->chain_seq : null,
                'payload_hash' => $count->status === CountStatus::Locked ? $count->payload_hash : null,
                'locked_at' => $count->status === CountStatus::Locked ? $count->locked_at?->format('Y-m-d\TH:i:s.uP') : null,
                'provisional' => $provisional,
                'float_left_total' => $float,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<LocationView>  $places
     * @return list<StockCount>
     */
    private function pending(array $places): array
    {
        if ($places === []) {
            return [];
        }

        return array_values(StockCount::query()
            ->whereIn('location_id', array_map(static fn (LocationView $p): int => $p->id, $places))
            ->whereIn('status', [CountStatus::AwaitingCosign->value, CountStatus::PendingApproval->value])
            ->orderBy('id')->get()->all());
    }
}

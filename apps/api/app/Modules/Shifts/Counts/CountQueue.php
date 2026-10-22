<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Counts;

use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Station;
use App\Shared\Values\Quantity;
use Illuminate\Database\Eloquent\Builder;

/**
 * The approval queue (phase-3 plan section 4, GET /stock-counts; step 3.3c): the counts of the stations the caller works at that wait
 * for a co-signature or a decision, oldest submitted first. A row is a CountSummary with what an approver needs without opening the
 * count: the station, the shift, who counted (and who co-signed), and the lines that differ with expected, counted, variance, reason
 * and `moved_since`. Branch-scoped through the models (rule 8); resolved per use.
 */
final class CountQueue
{
    public const int DEFAULT_LIMIT = 50;

    public function __construct(private readonly CountPresenter $presenter) {}

    /**
     * The waiting counts of the stations, with the filters that scope them (not the status filter).
     *
     * @param  list<int>  $stationIds
     * @return Builder<StockCount>
     */
    public function scope(array $stationIds, ?int $stationId, ?int $countedBy): Builder
    {
        $shifts = ShiftInstance::query()->whereIn('station_id', $stationIds)->select('id');
        if ($stationId !== null) {
            $shifts->where('station_id', $stationId);
        }
        $query = StockCount::query()
            ->whereIn('status', [CountStatus::AwaitingCosign->value, CountStatus::PendingApproval->value])
            ->whereIn('shift_instance_id', $shifts);
        if ($countedBy !== null) {
            $query->where('counted_by_user_id', $countedBy);
        }

        return $query;
    }

    /**
     * The totals by status of the scope.
     *
     * @param  Builder<StockCount>  $scope
     * @return array{awaiting_cosign: int, pending_approval: int}
     */
    public function totals(Builder $scope): array
    {
        $by = (clone $scope)->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();

        return [
            'awaiting_cosign' => (int) ($by[CountStatus::AwaitingCosign->value] ?? 0),
            'pending_approval' => (int) ($by[CountStatus::PendingApproval->value] ?? 0),
        ];
    }

    /**
     * @param  Builder<StockCount>  $scope
     * @return list<array<string, mixed>>
     */
    public function rows(Builder $scope, ?CountStatus $status, int $limit): array
    {
        $query = (clone $scope)->orderBy('submitted_at')->orderBy('id')->limit($limit);
        if ($status instanceof CountStatus) {
            $query->where('status', $status->value);
        }
        $counts = array_values($query->get()->all());
        if ($counts === []) {
            return [];
        }

        $shifts = ShiftInstance::query()->whereKey(array_values(array_unique(array_map(static fn (StockCount $c): int => $c->shift_instance_id, $counts))))->get()->keyBy('id');
        $stations = Station::query()->withTrashed()->whereKey($shifts->pluck('station_id')->unique()->values()->all())->get()->keyBy('id');

        $out = [];
        foreach ($this->presenter->many($counts) as $row) {
            $shift = $shifts[$row['shift_id']];
            $station = $stations[$shift->station_id];
            $varying = array_values(array_filter(
                $row['lines'],
                static fn (array $line): bool => is_string($line['variance_qty']) && ! Quantity::of($line['variance_qty'])->isZero(),
            ));
            $row['lines_count'] = count($row['lines']);
            unset($row['lines'], $row['cash'], $row['below_reserved']);
            $out[] = $row + [
                'station' => ['id' => $station->id, 'code' => $station->code->value, 'name_en' => $station->name_en, 'name_am' => $station->name_am, 'requires_overseer' => $station->requires_overseer],
                'shift' => ['id' => $shift->id, 'date' => $shift->date->toDateString(), 'status' => $shift->status->value],
                'variance_lines' => $varying,
            ];
        }

        return $out;
    }
}

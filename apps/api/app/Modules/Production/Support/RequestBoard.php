<?php

declare(strict_types=1);

namespace App\Modules\Production\Support;

use App\Modules\Production\Enums\RequestPriority;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The board of production requests (phase-2 plan section 4, GET /production/requests): which requests a filter shows, in what
 * order, the counts for the header, and the ETag a poll is answered 304 from.
 *
 * Filters: ?status=, ?station_id=, ?product_item_id= and ?mine=1 (assigned to the person or asked for by them) narrow the list;
 * ?view=board shows the open requests plus those done today (the branch's local calendar day) in the order a baker works them:
 * open before done, urgent before high before normal, the earlier due time first (none last), the older request first, and the
 * done ones last, newest first. Without a view the newest come first. The counts are of the branch (narrowed by the station,
 * the product and mine, never by status or view): requested, accepted, in_progress, pending_confirmation, and done_today.
 *
 * The ETag is computed from the count, the newest updated_at and the sum of versions of the branch's requests and of its
 * batches, so two edits inside one second still change it (a version moves on every write), and from everything the answer
 * depends on: the filters, the person for mine, the limit and the local day (done today rolls over at midnight). Names of
 * products and people are not in it: a rename shows at the next write. Resolved per use.
 */
final class RequestBoard
{
    public const string ETAG_SCOPE = 'production-requests';

    public function __construct(private readonly CurrentBranch $branch) {}

    /**
     * @param  array{board: bool, status: RequestStatus|null, station: int|null, product: int|null, mine: bool, limit: int}  $filter
     */
    public function etag(array $filter, ?int $personId, CarbonImmutable $now): string
    {
        $requests = $this->summary(ProductionRequest::query());
        $batches = $this->summary(ProductionBatch::query());

        return ConditionalPoll::etag(self::ETAG_SCOPE, [
            'requests' => $requests['n'],
            'requests_touched' => $requests['touched'],
            'requests_versions' => $requests['versions'],
            'batches' => $batches['n'],
            'batches_touched' => $batches['touched'],
            'batches_versions' => $batches['versions'],
            'day' => $this->today($now)->toDateString(),
            'board' => $filter['board'],
            'status' => $filter['status']?->value,
            'station' => $filter['station'],
            'product' => $filter['product'],
            'mine' => $filter['mine'] ? $personId : null,
            'limit' => $filter['limit'],
        ]);
    }

    /**
     * The count, the newest updated_at and the sum of versions of the branch's rows of a table.
     *
     * @param  Builder<covariant Model>  $query
     * @return array{n: int, touched: string|null, versions: int}
     */
    private function summary(Builder $query): array
    {
        $row = $query->toBase()->selectRaw('count(*) as n, max(updated_at) as touched, coalesce(sum(version), 0) as versions')->first();
        if ($row === null) {
            return ['n' => 0, 'touched' => null, 'versions' => 0];
        }

        return ['n' => (int) $row->n, 'touched' => is_string($row->touched) ? $row->touched : null, 'versions' => (int) $row->versions];
    }

    /**
     * @param  array{board: bool, status: RequestStatus|null, station: int|null, product: int|null, mine: bool, limit: int}  $filter
     * @return Collection<int, ProductionRequest>
     */
    public function requests(array $filter, ?int $personId, CarbonImmutable $now): Collection
    {
        $query = $this->narrowed($filter, $personId);
        if ($filter['status'] !== null) {
            $query->where('status', $filter['status']->value);
        }

        if (! $filter['board']) {
            return $query->orderByDesc('id')->limit($filter['limit'])->get();
        }

        $query->where(function (Builder $q) use ($now): void {
            $q->whereIn('status', array_map(static fn (RequestStatus $s): string => $s->value, RequestStatus::open()))
                ->orWhere(function (Builder $done) use ($now): void {
                    [$from, $to] = $this->dayBounds($now);
                    $done->where('status', RequestStatus::Done->value)->where('done_at', '>=', $from)->where('done_at', '<', $to);
                });
        });

        return $query->get()->sort(self::workingOrder(...))->take($filter['limit'])->values();
    }

    /**
     * @param  array{board: bool, status: RequestStatus|null, station: int|null, product: int|null, mine: bool, limit: int}  $filter
     * @return array{requested: int, accepted: int, in_progress: int, pending_confirmation: int, done_today: int}
     */
    public function counts(array $filter, ?int $personId, CarbonImmutable $now): array
    {
        $byStatus = $this->narrowed($filter, $personId)
            ->toBase()
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');
        [$from, $to] = $this->dayBounds($now);
        $doneToday = $this->narrowed($filter, $personId)
            ->where('status', RequestStatus::Done->value)->where('done_at', '>=', $from)->where('done_at', '<', $to)->count();

        return [
            'requested' => (int) ($byStatus[RequestStatus::Requested->value] ?? 0),
            'accepted' => (int) ($byStatus[RequestStatus::Accepted->value] ?? 0),
            'in_progress' => (int) ($byStatus[RequestStatus::InProgress->value] ?? 0),
            'pending_confirmation' => (int) ($byStatus[RequestStatus::PendingConfirmation->value] ?? 0),
            'done_today' => $doneToday,
        ];
    }

    /**
     * The filters that narrow by place, product and person (never by status or view).
     *
     * @param  array{board: bool, status: RequestStatus|null, station: int|null, product: int|null, mine: bool, limit: int}  $filter
     * @return Builder<ProductionRequest>
     */
    private function narrowed(array $filter, ?int $personId): Builder
    {
        $query = ProductionRequest::query();
        if ($filter['station'] !== null) {
            $query->where('station_id', $filter['station']);
        }
        if ($filter['product'] !== null) {
            $query->where('product_item_id', $filter['product']);
        }
        if ($filter['mine']) {
            $query->where(fn (Builder $q) => $q->where('assigned_to_user_id', $personId)->orWhere('requested_by_user_id', $personId));
        }

        return $query;
    }

    /**
     * The branch's local day containing $now, as the UTC instants it starts and ends at.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function dayBounds(CarbonImmutable $now): array
    {
        $start = $this->today($now)->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    private function today(CarbonImmutable $now): CarbonImmutable
    {
        return $now->setTimezone($this->branch->timezone());
    }

    private static function workingOrder(ProductionRequest $a, ProductionRequest $b): int
    {
        $done = static fn (ProductionRequest $r): int => $r->status === RequestStatus::Done ? 1 : 0;
        $rank = static fn (ProductionRequest $r): int => match ($r->priority) {
            RequestPriority::Urgent => 0,
            RequestPriority::High => 1,
            RequestPriority::Normal => 2,
        };
        $due = static fn (ProductionRequest $r): int => $r->due_at?->getTimestamp() ?? PHP_INT_MAX;

        if ($done($a) !== $done($b)) {
            return $done($a) <=> $done($b);
        }
        if ($done($a) === 1) {
            return ($b->done_at?->getTimestamp() ?? 0) <=> ($a->done_at?->getTimestamp() ?? 0) ?: $b->id <=> $a->id;
        }

        return [$rank($a), $due($a), $a->id] <=> [$rank($b), $due($b), $b->id];
    }
}

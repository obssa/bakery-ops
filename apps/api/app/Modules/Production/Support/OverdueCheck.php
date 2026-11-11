<?php

declare(strict_types=1);

namespace App\Modules\Production\Support;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Production\Enums\RequestPriority;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Events\ProductionOverdue;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;

/**
 * The production requests nobody has started that wait too long (Ramin 2026-11-11: "if a request stays in Requested for a long time, what
 * happens?"): a request still `requested` or `accepted` whose age passed production.waiting_too_long_minutes (production.urgent_minutes for
 * an urgent one), or whose due time has passed. One ProductionOverdue per such request, in every branch; Notifications tells the station's
 * planners once per request (its gate), so running this every 15 minutes repeats nothing. The web board draws the same rule
 * (lib/productionBoard.ts requestWait). Resolved per use.
 */
final class OverdueCheck
{
    public function __construct(
        private readonly Items $items,
        private readonly CurrentBranch $branch,
    ) {}

    /**
     * @return list<ProductionOverdue>
     */
    public function check(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone('UTC');
        $waiting = max(1, (int) config('production.waiting_too_long_minutes', 120));
        $urgent = max(1, (int) config('production.urgent_minutes', 30));
        $statuses = [RequestStatus::Requested->value, RequestStatus::Accepted->value];

        $rows = ProductionRequest::query()->withoutGlobalScope(BranchScope::class)
            ->whereIn('status', $statuses)
            ->where(function ($q) use ($now, $waiting, $urgent): void {
                $q->where('created_at', '<=', $now->subMinutes($waiting))
                    ->orWhere(fn ($u) => $u->where('priority', RequestPriority::Urgent->value)->where('created_at', '<=', $now->subMinutes($urgent)))
                    ->orWhere(fn ($d) => $d->whereNotNull('due_at')->where('due_at', '<', $now));
            })
            ->orderBy('id')
            ->get();

        $events = [];
        foreach ($rows->groupBy('branch_id') as $branchId => $requests) {
            $this->branch->runAs((int) $branchId, function () use ($requests, $now, &$events): void {
                $names = $this->items->summaries(array_values(array_unique(array_map(static fn (ProductionRequest $r): int => (int) $r->product_item_id, $requests->all()))));
                foreach ($requests as $request) {
                    $station = Station::query()->withTrashed()->find($request->station_id);
                    $summary = $names[$request->product_item_id] ?? null;
                    $created = $request->created_at ?? $now;
                    $events[] = new ProductionOverdue(
                        (int) $request->branch_id,
                        (int) $request->id,
                        (int) $request->product_item_id,
                        $summary->nameEn ?? '',
                        $summary->nameAm ?? '',
                        $request->requested_qty->toString(),
                        $summary->unit ?? '',
                        (int) $request->station_id,
                        $station->name_en ?? '',
                        $station->name_am ?? '',
                        $request->status->value,
                        $request->priority->value,
                        (int) max(0, $created->diffInMinutes($now, true)),
                        $request->due_at !== null && $request->due_at->lessThan($now),
                        $now,
                    );
                }
            });
        }

        return $events;
    }
}

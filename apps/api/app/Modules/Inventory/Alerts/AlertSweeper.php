<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Alerts;

use App\Modules\Inventory\Jobs\EvaluateBalance;
use App\Modules\Inventory\Ledger\LedgerReader;
use App\Modules\Inventory\Models\ReorderRule;
use App\Modules\Inventory\Models\StockAlertState;
use App\Shared\Tenancy\BranchScope;
use Illuminate\Database\Eloquent\Collection;

/**
 * The hourly safety net of the stock alerts (phase-2 plan section 6 item 6; ADR 0011): queues the unique EvaluateBalance of every
 * balance that has an open episode (low or out) or a live, active reorder rule, in every branch, so an evaluation that was lost
 * (a job that died, a uniqueness lock that outlived it) is redone within the hour. The job reads the current balance, so queueing
 * one that was not lost changes nothing; and it is unique until it starts, so sweeping again while the first jobs wait queues nothing.
 *
 * Bounded: the episodes and the rules are read in chunks by id (never every balance at once), and a branch-wide key (an episode or
 * rule that names no place) is evaluated through the lowest-numbered stock place of its item, as ReorderRules does, one lookup per
 * chunk. A balance is queued once per sweep however many rules and episodes name it. Runs in a process with no current branch, so
 * it reads the tables without the branch scope; each job carries its own branch id.
 */
final class AlertSweeper
{
    public function __construct(private readonly LedgerReader $reader) {}

    /**
     * @return int how many evaluations were queued
     */
    public function sweep(int $chunk = 500): int
    {
        $seen = [];
        $queued = 0;

        StockAlertState::query()->withoutGlobalScope(BranchScope::class)->where('is_active', 1)
            ->chunkById($chunk, function (Collection $episodes) use (&$seen, &$queued): void {
                $queued += $this->queue($episodes, $seen);
            });
        ReorderRule::query()->withoutGlobalScope(BranchScope::class)->where('is_active', true)
            ->chunkById($chunk, function (Collection $rules) use (&$seen, &$queued): void {
                $queued += $this->queue($rules, $seen);
            });

        return $queued;
    }

    /**
     * @param  Collection<int, StockAlertState>|Collection<int, ReorderRule>  $rows
     * @param  array<string, true>  $seen  the balances already queued by this sweep
     */
    private function queue(Collection $rows, array &$seen): int
    {
        /** @var list<int> $wide */
        $wide = [];
        foreach ($rows as $row) {
            if ($row->location_id === null) {
                $wide[] = $row->item_id;
            }
        }
        $places = $this->reader->lowestStockPlaces(array_values(array_unique($wide)));

        $queued = 0;
        foreach ($rows as $row) {
            $locationId = $row->location_id ?? ($places[$row->item_id] ?? null);
            if ($locationId === null) {
                continue; // a branch-wide key whose item no stock place ever held: nothing to evaluate
            }
            $key = "{$row->branch_id}:{$row->item_id}:{$locationId}";
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            EvaluateBalance::dispatch($row->branch_id, $row->item_id, $locationId);
            $queued++;
        }

        return $queued;
    }
}

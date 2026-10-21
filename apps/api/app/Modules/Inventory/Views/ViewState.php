<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Views;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Models\ReorderRule;

/**
 * The cheap numbers the ETags of the polled stock views are computed from (ConditionalPoll, ADR 0009): the ledger and the
 * locations (Stock::fingerprint), the reorder rules, and Catalog's version (names, units, recipes, stations). A handful of
 * indexed aggregate queries that do not grow with the number of items. Resolved per use (it reads the current branch).
 */
final class ViewState
{
    public function __construct(private readonly Stock $stock, private readonly Items $items) {}

    /**
     * @return array{last_movement_id: int|null, locations_n: int, locations_at: string|null}
     */
    public function stock(): array
    {
        return $this->stock->fingerprint();
    }

    /**
     * The state of the branch's reorder rules, deleted ones included (a soft delete touches updated_at and adds to the
     * deleted count; an edit adds to the version sum), so any change to what a strip shows against a minimum moves the tag.
     *
     * @return array{rules_n: int, rules_deleted: int, rules_versions: int, rules_at: string|null}
     */
    public function rules(): array
    {
        $row = ReorderRule::query()->withTrashed()->toBase()
            ->selectRaw('count(*) as n, count(deleted_at) as gone, coalesce(sum(version), 0) as versions, max(updated_at) as touched')
            ->first();
        $touched = $row?->touched;

        return [
            'rules_n' => (int) ($row?->n),
            'rules_deleted' => (int) ($row?->gone),
            'rules_versions' => (int) ($row?->versions),
            'rules_at' => is_string($touched) ? $touched : null,
        ];
    }

    /** Catalog's version of the branch: bumped by every write to items, units, recipes and station assignments. */
    public function catalogVersion(): int
    {
        return (int) ($this->items->menuFingerprint()['catalog_version'] ?? 0);
    }
}

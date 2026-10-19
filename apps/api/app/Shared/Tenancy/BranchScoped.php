<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rule 8 for Eloquent models: reads are limited to the resolved CurrentBranch and branch_id is filled on
 * create. Every tenant model uses this trait (tests/Arch/BranchScopedTest.php).
 */
trait BranchScoped
{
    public static function bootBranchScoped(): void
    {
        static::addGlobalScope(new BranchScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('branch_id') !== null) {
                return;
            }
            $current = app(CurrentBranch::class);
            if ($current->isResolved()) {
                $model->setAttribute('branch_id', $current->id());
            }
        });
    }

    /**
     * Explicit cross-branch access for admin work: lifts the global scope and pins another branch.
     *
     * @param  Builder<Model>  $query
     */
    #[Scope]
    protected function forBranch(Builder $query, int $branchId): void
    {
        $query->withoutGlobalScope(BranchScope::class)->where($this->qualifyColumn('branch_id'), $branchId);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}

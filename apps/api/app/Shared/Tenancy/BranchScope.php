<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope added by BranchScoped: when a CurrentBranch is resolved, every query is limited to it.
 * Lift it deliberately with withoutGlobalScope(BranchScope::class) or the forBranch() scope.
 *
 * @implements Scope<Model>
 */
final class BranchScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $current = app(CurrentBranch::class);

        if ($current->isResolved()) {
            $builder->where($model->qualifyColumn('branch_id'), $current->id());
        }
    }
}

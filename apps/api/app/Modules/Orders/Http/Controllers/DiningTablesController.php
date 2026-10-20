<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Contracts\Access;
use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Orders\Http\Requests\StoreDiningTableRequest;
use App\Modules\Orders\Http\Requests\UpdateDiningTableRequest;
use App\Modules\Orders\Http\Resources\DiningTableResource;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Support\TableStates;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The dining tables of the caller's branch (orders.create to read the pad's grid, tables.manage to write). Every write
 * is one AuditedAction; another branch's table is 404 because route binding goes through the BranchScoped query
 * (rule 8). A code is used once per branch, an inactive table included (orders snapshot it), compared without regard
 * to case. Tables are switched off with is_active, never deleted from the API. GET returns the stored fields and the
 * derived state (Support\TableStates, one grouped query for the whole grid) in the grid's order (sort, code, id).
 */
final class DiningTablesController
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly CurrentBranch $branch,
        private readonly Access $access,
        private readonly ActorContext $actor,
        private readonly TableStates $states,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $all = $request->boolean('include_inactive') && $user !== null && $this->access->can($user, 'tables.manage');

        $tables = DiningTable::query()
            ->when(! $all, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort')->orderBy('code')->orderBy('id')
            ->get();

        $states = $this->states->for($tables);

        return response()->json(['data' => $tables->map(
            fn (DiningTable $t): array => (new DiningTableResource($t, $states[$t->id] ?? TableStates::FREE))->resolve($request),
        )->values()->all()]);
    }

    public function store(StoreDiningTableRequest $request): JsonResponse
    {
        $attributes = $request->tableAttributes();
        $this->assertCodeFree($attributes['code']);

        try {
            $table = $this->audit->run(function () use ($attributes): DiningTable {
                $table = new DiningTable;
                $table->fill($attributes);
                $table->branch_id = $this->branch->id();
                $table->created_by_user_id = $this->actor->requireUserId();
                $table->save();

                return $table;
            }, null, 'dining_table.create');
        } catch (UniqueConstraintViolationException) {
            throw OrderError::tableCodeTaken();
        }

        return response()->json($this->one($request, $table->fresh() ?? $table), 201);
    }

    public function update(UpdateDiningTableRequest $request, DiningTable $table): JsonResponse
    {
        $changes = $request->changes();

        $this->audit->run(function (DiningTable $t) use ($changes): DiningTable {
            $t->fill($changes)->save();

            return $t;
        }, $table, 'dining_table.update');

        return response()->json($this->one($request, $table->fresh() ?? $table));
    }

    /** @return array<string, mixed> */
    private function one(Request $request, DiningTable $table): array
    {
        return (new DiningTableResource($table, $this->states->for([$table])[$table->id] ?? TableStates::FREE))->resolve($request);
    }

    private function assertCodeFree(string $code): void
    {
        $taken = DiningTable::query()->withTrashed()->whereRaw('lower(code) = ?', [mb_strtolower($code)])->exists();
        if ($taken) {
            throw OrderError::tableCodeTaken();
        }
    }
}

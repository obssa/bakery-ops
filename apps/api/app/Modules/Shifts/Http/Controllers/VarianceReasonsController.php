<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Controllers;

use App\Modules\Shifts\Actions\SaveVarianceReason;
use App\Modules\Shifts\Http\Requests\StoreVarianceReasonRequest;
use App\Modules\Shifts\Http\Requests\UpdateVarianceReasonRequest;
use App\Modules\Shifts\Models\VarianceReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Variance reasons (phase-3 plan section 4, "Templates and reasons", step 3.3a): shifts.view reads, shifts.manage (the admin) writes.
 * Every write is an AuditedAction behind an Idempotency-Key; another branch's reason is 404 through the BranchScoped route binding
 * (rule 8), and so is a removed one.
 *
 *   GET   /variance-reasons             200 {data: [Reason]} by sort and id; ?active=1 leaves out the switched-off ones
 *   POST  /variance-reasons             201 the Reason; 409 shifts.reason.code_taken
 *   PATCH /variance-reasons/{reason}    200 the Reason (the code never changes)
 */
final class VarianceReasonsController
{
    public function index(Request $input): JsonResponse
    {
        $query = VarianceReason::query()->orderBy('sort')->orderBy('id');
        if ($input->boolean('active')) {
            $query->where('is_active', true);
        }

        return response()->json(['data' => $query->get()->map(fn (VarianceReason $r): array => self::resource($r))->all()]);
    }

    public function store(StoreVarianceReasonRequest $input, SaveVarianceReason $save): JsonResponse
    {
        return response()->json(self::resource($save->create($input->fields())), 201);
    }

    public function update(UpdateVarianceReasonRequest $input, VarianceReason $reason, SaveVarianceReason $save): JsonResponse
    {
        return response()->json(self::resource($save->update($reason, $input->changes())->refresh()));
    }

    /**
     * @return array{id: int, code: string, name_en: string, name_am: string, requires_note: bool, is_active: bool, sort: int}
     */
    public static function resource(VarianceReason $reason): array
    {
        return [
            'id' => $reason->id,
            'code' => $reason->code,
            'name_en' => $reason->name_en,
            'name_am' => $reason->name_am,
            'requires_note' => $reason->requires_note,
            'is_active' => $reason->is_active,
            'sort' => $reason->sort,
        ];
    }
}

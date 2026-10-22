<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Controllers;

use App\Modules\Shifts\Actions\DeleteTemplate;
use App\Modules\Shifts\Actions\SaveTemplate;
use App\Modules\Shifts\Http\Requests\ListTemplatesRequest;
use App\Modules\Shifts\Http\Requests\StoreTemplateRequest;
use App\Modules\Shifts\Http\Requests\UpdateTemplateRequest;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Modules\Shifts\Models\ShiftTemplateStation;
use App\Modules\Shifts\Support\TemplatePresenter;
use Illuminate\Http\JsonResponse;

/**
 * Shift templates (phase-3 plan section 4, step 3.2b): shifts.view reads, shifts.manage (the admin) writes. Every write is an
 * AuditedAction in the action classes behind an Idempotency-Key; another branch's template is 404 through the BranchScoped route
 * binding (rule 8), and so is a deleted one.
 *
 *   GET    /shift-templates              200 {data: [Template]} by sort and id; ?active=1, ?station_id=
 *   POST   /shift-templates              201 the Template
 *   PATCH  /shift-templates/{template}   200 the Template (instances already made keep their own window)
 *   DELETE /shift-templates/{template}   200 the Template as it was (soft-deleted)
 */
final class TemplatesController
{
    public function __construct(private readonly TemplatePresenter $presenter) {}

    public function index(ListTemplatesRequest $input): JsonResponse
    {
        $query = ShiftTemplate::query()->orderBy('sort')->orderBy('id');
        if ($input->activeOnly()) {
            $query->where('is_active', true);
        }
        if ($input->stationId() !== null) {
            $query->whereIn('id', ShiftTemplateStation::query()->where('station_id', $input->stationId())->select('template_id'));
        }

        return response()->json(['data' => $this->presenter->many($query->get())]);
    }

    public function store(StoreTemplateRequest $input, SaveTemplate $save): JsonResponse
    {
        return response()->json($this->presenter->one($save->create($input->fields())), 201);
    }

    public function update(UpdateTemplateRequest $input, ShiftTemplate $template, SaveTemplate $save): JsonResponse
    {
        $save->update($template, $input->changes());

        return response()->json($this->presenter->one($template->refresh()));
    }

    public function destroy(ShiftTemplate $template, DeleteTemplate $delete): JsonResponse
    {
        return response()->json($this->presenter->one($delete->handle($template)));
    }
}

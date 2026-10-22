<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Modules\Shifts\Models\ShiftTemplateStation;
use App\Modules\Shifts\Support\TemplateWindow;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use InvalidArgumentException;

/**
 * POST /shift-templates and PATCH /shift-templates/{template} (phase-3 plan section 4): a named window of the day on the days of the
 * week at some stations. One AuditedAction each (shift_template.create, shift_template.update). The window is checked by
 * TemplateWindow (the one place that knows what a window is): a start equal to the end is 422 shifts.template.window_invalid. Every
 * station named must be a live station of the branch (422 shifts.template.station_unknown). The station pairs are replaced as a set:
 * a pair that goes is soft-deleted, one that comes back is restored, never duplicated (unique template + station). Editing a template
 * never changes an instance already made: instances copy the window into starts_at and ends_at.
 */
final class SaveTemplate
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @param  array{name_en: string, name_am: string, start_time: string, end_time: string, days_of_week: list<int>, station_ids: list<int>, is_active?: bool, sort?: int}  $input
     *
     * @throws ShiftError shifts.template.window_invalid, shifts.template.station_unknown (422)
     */
    public function create(array $input): ShiftTemplate
    {
        $window = $this->window($input['start_time'], $input['end_time'], $input['days_of_week']);
        $stationIds = $this->stations($input['station_ids']);

        return $this->audit->run(function () use ($input, $window, $stationIds): ShiftTemplate {
            $stamp = $this->actor->stamp();
            $template = new ShiftTemplate;
            $template->forceFill([
                'branch_id' => $this->branch->id(),
                'name_en' => $input['name_en'],
                'name_am' => $input['name_am'],
                'start_time' => $window->startTime,
                'end_time' => $window->endTime,
                'days_mask' => $window->daysMask,
                'is_active' => $input['is_active'] ?? true,
                'sort' => $input['sort'] ?? 0,
                'created_by_user_id' => $stamp['created_by_user_id'],
                'acting_user_id' => $stamp['acting_user_id'],
                'shift_instance_id' => $stamp['shift_instance_id'],
                'actor_station_id' => $stamp['station_id'],
                'device_id' => $stamp['device_id'],
                'version' => 1,
            ])->save();
            $this->pairs($template, $stationIds);

            return $template;
        }, null, 'shift_template.create');
    }

    /**
     * @param  array<string, mixed>  $changes  any of name_en, name_am, start_time, end_time, days_of_week, station_ids, is_active, sort
     *
     * @throws ShiftError shifts.template.window_invalid, shifts.template.station_unknown (422)
     */
    public function update(ShiftTemplate $template, array $changes): ShiftTemplate
    {
        $current = $template->window();
        $window = $this->window(
            is_string($changes['start_time'] ?? null) ? $changes['start_time'] : $current->startTime,
            is_string($changes['end_time'] ?? null) ? $changes['end_time'] : $current->endTime,
            isset($changes['days_of_week']) && is_array($changes['days_of_week']) ? array_values(array_map('intval', $changes['days_of_week'])) : $current->daysOfWeek(),
        );
        $stationIds = isset($changes['station_ids']) && is_array($changes['station_ids']) ? $this->stations(array_values(array_map('intval', $changes['station_ids']))) : null;

        return $this->audit->run(function (ShiftTemplate $t) use ($changes, $window, $stationIds): ShiftTemplate {
            $fill = ['start_time' => $window->startTime, 'end_time' => $window->endTime, 'days_mask' => $window->daysMask];
            foreach (['name_en', 'name_am', 'is_active', 'sort'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $fill[$field] = $changes[$field];
                }
            }
            $t->forceFill($fill + ['version' => $t->version + 1])->save();
            if ($stationIds !== null) {
                $this->pairs($t, $stationIds);
            }

            return $t;
        }, $template, 'shift_template.update');
    }

    /**
     * @param  list<int>  $days
     *
     * @throws ShiftError shifts.template.window_invalid (422)
     */
    private function window(string $start, string $end, array $days): TemplateWindow
    {
        try {
            return new TemplateWindow(0, $start, $end, TemplateWindow::maskOf($days));
        } catch (InvalidArgumentException) {
            throw ShiftError::windowInvalid();
        }
    }

    /**
     * @param  list<int>  $ids
     * @return list<int> the same ids, unique and ascending
     *
     * @throws ShiftError shifts.template.station_unknown (422)
     */
    private function stations(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        $known = Station::query()->whereIn('id', $ids)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $unknown = array_values(array_diff($ids, $known));
        if ($unknown !== [] || $ids === []) {
            throw ShiftError::templateStationUnknown($unknown);
        }

        return $ids;
    }

    /**
     * Make $stationIds the template's pairs: restore a pair that was removed, soft-delete one that goes, make the new ones.
     *
     * @param  list<int>  $stationIds
     */
    private function pairs(ShiftTemplate $template, array $stationIds): void
    {
        $rows = ShiftTemplateStation::query()->withTrashed()->where('template_id', $template->id)->get()->keyBy('station_id');
        foreach ($rows as $stationId => $row) {
            if (! $row->trashed() && ! in_array((int) $stationId, $stationIds, true)) {
                $row->delete();
            }
        }
        foreach ($stationIds as $stationId) {
            $row = $rows->get($stationId);
            if ($row instanceof ShiftTemplateStation) {
                if ($row->trashed()) {
                    $row->restore();
                }

                continue;
            }
            $pair = new ShiftTemplateStation;
            $pair->forceFill(['branch_id' => $this->branch->id(), 'template_id' => $template->id, 'station_id' => $stationId])->save();
        }
    }
}

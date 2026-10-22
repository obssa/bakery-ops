<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Support;

use App\Modules\Shifts\Models\ShiftTemplate;
use App\Modules\Shifts\Models\ShiftTemplateStation;

/**
 * The Template of the API (phase-3 plan section 4): the window as the branch's local HH:MM, the weekdays 1 (Monday) to 7, and the
 * ids of the stations it serves. Resolved per use.
 */
final class TemplatePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function one(ShiftTemplate $template): array
    {
        return $this->many([$template])[0];
    }

    /**
     * @param  iterable<ShiftTemplate>  $templates
     * @return list<array<string, mixed>>
     */
    public function many(iterable $templates): array
    {
        $list = [];
        foreach ($templates as $template) {
            $list[] = $template;
        }
        $pairs = ShiftTemplateStation::query()
            ->whereIn('template_id', array_map(static fn (ShiftTemplate $t): int => $t->id, $list))
            ->orderBy('station_id')
            ->get()
            ->groupBy('template_id');

        return array_map(static fn (ShiftTemplate $template): array => [
            'id' => $template->id,
            'name_en' => $template->name_en,
            'name_am' => $template->name_am,
            'start_time' => substr($template->start_time, 0, 5),
            'end_time' => substr($template->end_time, 0, 5),
            'days_of_week' => $template->window()->daysOfWeek(),
            'station_ids' => ($pairs->get($template->id) ?? collect())->map(static fn (ShiftTemplateStation $p): int => $p->station_id)->values()->all(),
            'is_active' => $template->is_active,
            'sort' => $template->sort,
            'version' => $template->version,
        ], $list);
    }
}

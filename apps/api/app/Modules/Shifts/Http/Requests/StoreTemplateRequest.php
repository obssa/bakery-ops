<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /shift-templates: a named window of the day on the days of the week at some stations. Times are the branch's local
 * wall-clock HH:MM (an end earlier than the start crosses midnight; equal times are refused by the action:
 * 422 shifts.template.window_invalid), days are ISO weekdays 1 (Monday) to 7, stations are checked against the branch by the action
 * (422 shifts.template.station_unknown).
 */
final class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name_en' => ['required', 'string', 'max:80'],
            'name_am' => ['required', 'string', 'max:80'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'days_of_week' => ['required', 'array', 'min:1', 'max:7'],
            'days_of_week.*' => ['integer', 'between:1,7', 'distinct'],
            'station_ids' => ['required', 'array', 'min:1'],
            'station_ids.*' => ['integer', 'min:1', 'distinct'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array{name_en: string, name_am: string, start_time: string, end_time: string, days_of_week: list<int>, station_ids: list<int>, is_active?: bool, sort?: int}
     */
    public function fields(): array
    {
        $v = $this->validated();
        $input = [
            'name_en' => trim((string) $v['name_en']),
            'name_am' => trim((string) $v['name_am']),
            'start_time' => (string) $v['start_time'],
            'end_time' => (string) $v['end_time'],
            'days_of_week' => array_values(array_map('intval', $v['days_of_week'])),
            'station_ids' => array_values(array_map('intval', $v['station_ids'])),
        ];
        if (array_key_exists('is_active', $v)) {
            $input['is_active'] = (bool) $v['is_active'];
        }
        if (array_key_exists('sort', $v)) {
            $input['sort'] = (int) $v['sort'];
        }

        return $input;
    }
}

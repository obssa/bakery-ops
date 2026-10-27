<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Requests;

use App\Modules\Analytics\Exceptions\AnalyticsError;
use App\Modules\Analytics\Weekly\WeeklySummary;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /analytics/weekly-summary/send (phase-4 plan section 4.6, step 4.4d): {week_of?}, any local date (YYYY-MM-DD) of the week to send;
 * without it the last complete week (on a Monday, the week that ended last night). A week that is not over (its Sunday is today or later)
 * is 422 analytics.weekly.week_open {week_start, week_end}; anything else wrong 422 validation.failed.
 */
final class WeeklySummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'week_of' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    /**
     * The Monday of the week asked for.
     *
     * @throws AnalyticsError analytics.weekly.week_open
     */
    public function weekStart(string $timezone): string
    {
        $of = $this->validated('week_of');
        $start = is_string($of) ? WeeklySummary::weekOf($of) : WeeklySummary::lastWeek($timezone);
        if (! WeeklySummary::isOver($start, $timezone)) {
            throw AnalyticsError::weekOpen($start, WeeklySummary::weekEnd($start));
        }

        return $start;
    }
}

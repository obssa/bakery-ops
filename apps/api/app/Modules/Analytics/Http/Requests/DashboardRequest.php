<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Requests;

use App\Modules\Analytics\Exceptions\AnalyticsError;
use App\Modules\Analytics\Read\Window;

/**
 * The query of every dashboard read (phase-4 plan section 4.6, step 4.4c): RangeRequest's ?from=&to= (the last 30 days by default, at most
 * 400, never after today) and ?compare=previous, which adds the same number of days just before the range (Window). Each read's own
 * options are its subclass's (SeriesRequest, ItemsRequest, StaffRequest); anything wrong is 422 validation.failed.
 */
class DashboardRequest extends RangeRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'compare' => ['sometimes', 'in:previous'],
        ];
    }

    /**
     * @throws AnalyticsError analytics.range.future, analytics.range.too_large
     */
    public function window(string $timezone): Window
    {
        return Window::of($this->range($timezone), $this->validated('compare') === 'previous');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Requests;

use App\Modules\Analytics\Read\Buckets;

/**
 * GET /analytics/sales and /analytics/channels (step 4.4c): the dashboard query and ?group=day|week|month, day by default (Buckets: weeks
 * start on Monday, local).
 */
final class SeriesRequest extends DashboardRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'group' => ['sometimes', 'in:'.implode(',', Buckets::GROUPS)],
        ];
    }

    public function group(): string
    {
        $group = $this->validated('group');

        return is_string($group) ? $group : 'day';
    }
}

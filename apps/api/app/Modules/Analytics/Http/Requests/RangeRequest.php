<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Requests;

use App\Modules\Analytics\Exceptions\AnalyticsError;
use App\Shared\Time\LocalDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The common query of the /analytics reads (phase-4 plan section 4.6; GET /analytics/freshness from step 4.4b, the dashboards from 4.4c):
 * ?from=&to= local dates (YYYY-MM-DD, from not after to). By default the last analytics.range_default_days (30) days, today included, in the
 * branch's zone; a missing `to` is today, a missing `from` that many days before `to`. A day after today is 422 analytics.range.future
 * {today}; more than analytics.range_max_days (400) days is 422 analytics.range.too_large {max_days}; anything else 422 validation.failed.
 * The dashboards' requests extend it (DashboardRequest: ?compare=previous, and each read's own options).
 */
class RangeRequest extends FormRequest
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
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => array_merge(['sometimes', 'date_format:Y-m-d'], $this->has('from') ? ['after_or_equal:from'] : []),
        ];
    }

    /**
     * @throws AnalyticsError analytics.range.future, analytics.range.too_large
     */
    public function range(string $timezone): LocalDay
    {
        $data = $this->validated();
        $today = CarbonImmutable::now($timezone)->toDateString();
        $to = isset($data['to']) && is_string($data['to']) ? $data['to'] : $today;
        $default = max(1, (int) config('analytics.range_default_days', 30));
        $from = isset($data['from']) && is_string($data['from']) ? $data['from'] : CarbonImmutable::parse($to, 'UTC')->subDays($default - 1)->toDateString();

        return self::bounded($from, $to, $today, max(1, (int) config('analytics.range_max_days', 400)), $timezone);
    }

    /**
     * A range of local days that ends today at the latest and holds at most $maxDays days.
     *
     * @throws AnalyticsError analytics.range.future, analytics.range.too_large
     */
    public static function bounded(string $from, string $to, string $today, int $maxDays, string $timezone): LocalDay
    {
        if ($from > $today || $to > $today) {
            throw AnalyticsError::rangeFuture($today);
        }
        $days = (int) CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($to, 'UTC'), true) + 1;
        if ($days > $maxDays) {
            throw AnalyticsError::rangeTooLarge($maxDays);
        }

        return LocalDay::between($from, $to, $timezone);
    }
}

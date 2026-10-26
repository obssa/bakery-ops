<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Requests;

use App\Modules\Analytics\Exceptions\AnalyticsError;
use App\Shared\Time\LocalDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /analytics/rebuild (phase-4 plan section 4.6, step 4.4b): {from, to?, sync?}. `from` and `to` are local dates (YYYY-MM-DD, from not
 * after to; a missing `to` is `from`), `sync` a boolean (false by default). Never a day after today (422 analytics.range.future {today}); at
 * most analytics.rebuild_inline_max_days (7) days with sync, analytics.rebuild_queue_max_days (31) without (422 analytics.range.too_large
 * {max_days}); anything else 422 validation.failed.
 */
final class RebuildRequest extends FormRequest
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
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sync' => ['sometimes', 'boolean'],
        ];
    }

    public function sync(): bool
    {
        return $this->boolean('sync');
    }

    /**
     * @throws AnalyticsError analytics.range.future, analytics.range.too_large
     */
    public function range(string $timezone): LocalDay
    {
        $data = $this->validated();
        $from = (string) $data['from'];
        $to = isset($data['to']) && is_string($data['to']) ? $data['to'] : $from;
        $max = $this->sync()
            ? max(1, (int) config('analytics.rebuild_inline_max_days', 7))
            : max(1, (int) config('analytics.rebuild_queue_max_days', 31));

        return RangeRequest::bounded($from, $to, CarbonImmutable::now($timezone)->toDateString(), $max, $timezone);
    }
}

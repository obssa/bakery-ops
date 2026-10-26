<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Exceptions;

use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every client-facing refusal of Analytics, as a stable message_key (lang/<locale>/analytics.php, rule 9), the HTTP status it maps to and what
 * it is about. Laravel renders it through render().
 */
final class AnalyticsError extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    private function __construct(
        public readonly string $messageKey,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($messageKey);
    }

    /** The range holds more local days than the endpoint reads or rebuilds at once. */
    public static function rangeTooLarge(int $maxDays): self
    {
        return new self('analytics.range.too_large', 422, ['field' => 'from', 'max_days' => $maxDays]);
    }

    /** The range reaches a local day after today in the branch's timezone. */
    public static function rangeFuture(string $today): self
    {
        return new self('analytics.range.future', 422, ['field' => 'to', 'today' => $today]);
    }

    /**
     * A queued rebuild of some of the same days is still waiting.
     *
     * @param  array{from: string, to: string, asked_at: string}  $running
     */
    public static function rebuildRunning(array $running): self
    {
        return new self('analytics.rebuild.running', 409, $running);
    }

    /** An export would hold more rows than analytics.export_max_rows. */
    public static function exportTooLarge(int $maxRows, int $rows): self
    {
        return new self('analytics.export.too_large', 422, ['max_rows' => $maxRows, 'rows' => $rows]);
    }

    /** The week asked for is not over: its Sunday is today or later in the branch's timezone. */
    public static function weekOpen(string $weekStart, string $weekEnd): self
    {
        return new self('analytics.weekly.week_open', 422, ['field' => 'week_of', 'week_start' => $weekStart, 'week_end' => $weekEnd]);
    }

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}

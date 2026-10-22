<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One window of one shift template on one date (phase-3 plan section 10.2; ADR 0013 rule 8), and the pure question "which
 * window contains this instant". `date` is the local start date in the branch's timezone, never the UTC date and never the
 * date the window ends: a Night 22:00 to 06:00 that is running at Saturday 00:01 belongs to Friday. startsAt and endsAt are
 * the window in UTC.
 *
 * For an instant at local time L, the start dates tried are L's date and the day before. The window starting on date d runs
 * from d at start_time to d at end_time, or to d + 1 at end_time when it crosses midnight; it contains the instant when
 * start <= t < end (end exclusive) and days_mask has the ISO weekday of d, the day the window starts. Of the matching windows
 * the one that started latest wins, a tie going to the lower template id. An instant no window contains is an ad-hoc shift,
 * dated by the local date of the instant (adHocDate).
 */
final readonly class ShiftWindow
{
    public function __construct(
        public int $templateId,
        public string $date,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}

    /**
     * The window of one template that starts on a local date (whether or not days_mask allows that weekday: the caller
     * decides that, as the scheduler does when it makes instances ahead).
     */
    public static function forDate(TemplateWindow $template, string $date, ?LocalClock $clock = null): self
    {
        $clock ??= new LocalClock;
        $endDate = $template->crossesMidnight() ? $clock->addDays($date, 1) : $date;

        return new self(
            $template->id,
            $date,
            $clock->toUtc($date, $template->startTime),
            $clock->toUtc($endDate, $template->endTime),
        );
    }

    /**
     * Every window that contains the instant, the one that started latest first (ties: lower template id first).
     *
     * @param  iterable<TemplateWindow>  $templates
     * @return list<self>
     */
    public static function matching(CarbonInterface $instant, iterable $templates, ?LocalClock $clock = null): array
    {
        $clock ??= new LocalClock;
        $t = CarbonImmutable::instance($instant)->utc();
        $today = $clock->localDate($t);
        $dates = [$today, $clock->addDays($today, -1)];

        $found = [];
        foreach ($templates as $template) {
            foreach ($dates as $date) {
                if (! $template->runsOn($clock->isoWeekday($date))) {
                    continue;
                }
                $window = self::forDate($template, $date, $clock);
                if ($window->startsAt <= $t && $t < $window->endsAt) {
                    $found[] = $window;
                }
            }
        }

        usort($found, static fn (self $a, self $b): int => [$b->startsAt, $a->templateId] <=> [$a->startsAt, $b->templateId]);

        return $found;
    }

    /**
     * The window that contains the instant, or null when none does (an ad-hoc shift).
     *
     * @param  iterable<TemplateWindow>  $templates
     */
    public static function containing(CarbonInterface $instant, iterable $templates, ?LocalClock $clock = null): ?self
    {
        return self::matching($instant, $templates, $clock)[0] ?? null;
    }

    /** The date of an ad-hoc shift opened at the instant: its local date. */
    public static function adHocDate(CarbonInterface $instant, ?LocalClock $clock = null): string
    {
        return ($clock ?? new LocalClock)->localDate($instant);
    }
}

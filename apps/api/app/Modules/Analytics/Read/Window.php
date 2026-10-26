<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Read;

use App\Shared\Time\LocalDay;
use Carbon\CarbonImmutable;

/**
 * What a dashboard read covers (phase-4 plan section 4.6, step 4.4c): the range asked for and, with ?compare=previous, the period just
 * before it with the same number of local days (a week before a week, the 29 January to 28 February before March). Every summary query
 * of a read covers first() to last() once and tells the two periods apart by the day (Summaries::period), so a comparison costs no
 * extra query. Pure.
 */
final readonly class Window
{
    private function __construct(
        public LocalDay $current,
        public ?LocalDay $previous,
    ) {}

    public static function of(LocalDay $current, bool $compare): self
    {
        if (! $compare) {
            return new self($current, null);
        }
        $from = CarbonImmutable::parse($current->from, 'UTC');
        $length = (int) $from->diffInDays(CarbonImmutable::parse($current->to, 'UTC'), true) + 1;

        return new self($current, LocalDay::between($from->subDays($length)->toDateString(), $from->subDay()->toDateString(), $current->timezone()));
    }

    /** The first local day any period of the window holds. */
    public function first(): string
    {
        return $this->previous->from ?? $this->current->from;
    }

    /** The last local day of the window (the range's own). */
    public function last(): string
    {
        return $this->current->to;
    }

    /**
     * The range, then the period before it when there is one: index 0 and 1, as Summaries::period numbers them.
     *
     * @return list<LocalDay>
     */
    public function periods(): array
    {
        return $this->previous === null ? [$this->current] : [$this->current, $this->previous];
    }
}

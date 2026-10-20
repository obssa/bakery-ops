<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Models\OrderLine;

/**
 * What OrderProgress needs to know of a line: its status, and whether it goes to a station (a display-case line has
 * none and is ready at send, ADR 0008, so its being ready says nothing about work started).
 */
final readonly class LineState
{
    public function __construct(
        public LineStatus $status,
        public bool $atStation,
    ) {}

    public static function of(OrderLine $line): self
    {
        return new self($line->status, $line->station_id !== null);
    }

    /** Still to be served or paid for: neither rejected by a station nor taken off the cart (voided). */
    public function isLive(): bool
    {
        return $this->status !== LineStatus::Rejected && $this->status !== LineStatus::Voided;
    }

    /** Work has started on it: a station took it up, or it was handed over. */
    public function isStarted(): bool
    {
        return ($this->atStation && $this->status !== LineStatus::Queued) || $this->status === LineStatus::Served;
    }
}

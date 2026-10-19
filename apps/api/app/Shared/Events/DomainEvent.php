<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Base of every cross-module event (dependency-graph.md, cycle breaks 2 and 7): consumers react to events
 * instead of importing producers. Payloads are arrays of ids, decimal strings and bilingual strings; never
 * Eloquent models, so a listener in another module cannot reach into the producer's tables.
 */
abstract class DomainEvent
{
    public readonly CarbonImmutable $occurredAt;

    public function __construct(public readonly int $branchId, ?CarbonImmutable $occurredAt = null)
    {
        $this->occurredAt = ($occurredAt ?? CarbonImmutable::now())->setTimezone('UTC');
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function payload(): array;

    /** snake_case class name, e.g. stock_changed; stable enough to log and to broadcast. */
    public function name(): string
    {
        return Str::snake(class_basename(static::class));
    }

    /**
     * @return array{event: string, branch_id: int, occurred_at: string, payload: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'event' => $this->name(),
            'branch_id' => $this->branchId,
            'occurred_at' => $this->occurredAt->toIso8601ZuluString(),
            'payload' => $this->payload(),
        ];
    }
}

<?php

declare(strict_types=1);

use App\Shared\Events\DomainEvent;
use Carbon\CarbonImmutable;

/*
 * DomainEvent is the base of every cross-module event: array payloads of ids and bilingual strings, never models,
 * stamped with the branch and a UTC time (dependency-graph.md, cycle break 7).
 */

final class StockCountedForTest extends DomainEvent
{
    public function __construct(int $branchId, public readonly int $itemId, public readonly string $qty, ?CarbonImmutable $at = null)
    {
        parent::__construct($branchId, $at);
    }

    public function payload(): array
    {
        return ['item_id' => $this->itemId, 'qty' => $this->qty];
    }
}

it('carries branch, UTC time and an array payload', function () {
    $event = new StockCountedForTest(3, 11, '2.5000');

    expect($event->branchId)->toBe(3)
        ->and($event->occurredAt->timezoneName)->toBe('UTC')
        ->and($event->payload())->toBe(['item_id' => 11, 'qty' => '2.5000'])
        ->and($event->name())->toBe('stock_counted_for_test')
        ->and($event->toArray())->toBe([
            'event' => 'stock_counted_for_test',
            'branch_id' => 3,
            'occurred_at' => $event->occurredAt->toIso8601ZuluString(),
            'payload' => ['item_id' => 11, 'qty' => '2.5000'],
        ]);
})->group('unit', 'module:shared');

it('keeps the time it is given, converted to UTC', function () {
    $local = CarbonImmutable::parse('2026-10-18 09:00:00', 'Africa/Addis_Ababa');
    $event = new StockCountedForTest(1, 1, '1.0000', $local);

    expect($event->occurredAt->toIso8601ZuluString())->toBe('2026-10-18T06:00:00Z');
})->group('unit', 'module:shared');

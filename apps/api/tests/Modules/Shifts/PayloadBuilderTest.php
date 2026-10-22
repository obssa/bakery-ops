<?php

declare(strict_types=1);

use App\Modules\Shifts\Hash\CanonicalJson;
use App\Modules\Shifts\Hash\PayloadBuilder;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ChainFixture;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * Hash\PayloadBuilder (ADR 0012, phase-3 plan section 10.3): the version 1 payload of a count, built from the live rows (the count, its
 * lines, their reasons, the shift) plus the three things the lock adds (chain_seq, locked_at, locked_by_user_id). Every key of the schema
 * is always present (null when it has no value); lines are ordered by item id; decimals are fixed-scale strings; timestamps UTC with six
 * fraction digits. The builder is also how verification rebuilds a payload, so it must give the same bytes for the same rows, always.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->chain = new ChainFixture($this->f);
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient();
    $this->sugar = $this->f->ingredient();
    $this->lockedAt = CarbonImmutable::parse('2026-11-02 12:31:02.482913', 'UTC');
});

it('carries every key of the version 1 schema, null where there is no value', function () {
    $count = $this->chain->approved($this->place, [['item' => $this->flour, 'counted' => '9500', 'expected' => '10000', 'reason' => 'spoilage']]);

    $payload = app(PayloadBuilder::class)->build($count, 4, $this->lockedAt, $this->f->admin->id);

    expect(array_keys($payload))->toEqualCanonicalizing([
        'branch_id', 'location_id', 'shift_instance_id', 'shift_date', 'station_id', 'type', 'seq', 'supersedes_count_id', 'snapshot_at', 'ledger_movement_id',
        'counted_by_user_id', 'cosigned_by_user_id', 'decision', 'decided_by_user_id', 'decided_at', 'decision_note', 'submitted_at', 'lines', 'cash',
        'chain_seq', 'locked_at', 'locked_by_user_id', 'payload_version',
    ]);
    expect($payload['branch_id'])->toBe($this->branch->id)
        ->and($payload['location_id'])->toBe($this->place->id)
        ->and($payload['shift_instance_id'])->toBe($count->shift_instance_id)
        ->and($payload['station_id'])->toBe($this->f->kitchen->id)
        ->and($payload['type'])->toBe('closing')
        ->and($payload['seq'])->toBe(1)
        ->and($payload['supersedes_count_id'])->toBeNull()
        ->and($payload['cosigned_by_user_id'])->toBeNull()
        ->and($payload['cash'])->toBeNull('the cash block is step 3.5')
        ->and($payload['decision'])->toBe('approved')
        ->and($payload['decided_by_user_id'])->toBe($this->f->admin->id)
        ->and($payload['counted_by_user_id'])->toBe($this->f->chef->id)
        ->and($payload['chain_seq'])->toBe(4)
        ->and($payload['locked_at'])->toBe('2026-11-02T12:31:02.482913Z')
        ->and($payload['locked_by_user_id'])->toBe($this->f->admin->id)
        ->and($payload['payload_version'])->toBe(1);
});

it('writes dates, instants and decimals in the canonical forms', function () {
    $count = $this->chain->approved($this->place, [['item' => $this->flour, 'counted' => '9500', 'expected' => '10000', 'reason' => 'spoilage']]);
    DB::table('stock_counts')->where('id', $count->id)->update(['snapshot_at' => '2026-11-02 12:00:00.123456', 'submitted_at' => '2026-11-02 12:00:00', 'decided_at' => '2026-11-02 12:30:00']);

    $payload = app(PayloadBuilder::class)->build($count->fresh(), 1, $this->lockedAt, $this->f->admin->id);

    expect($payload['snapshot_at'])->toBe('2026-11-02T12:00:00.123456Z')
        ->and($payload['submitted_at'])->toBe('2026-11-02T12:00:00.000000Z')
        ->and($payload['decided_at'])->toBe('2026-11-02T12:30:00.000000Z')
        ->and($payload['shift_date'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and($payload['lines'][0]['expected_qty'])->toBe('10000.0000')
        ->and($payload['lines'][0]['counted_qty'])->toBe('9500.0000')
        ->and($payload['lines'][0]['variance_qty'])->toBe('-500.0000')
        ->and($payload['lines'][0]['carried_qty'])->toBeNull();
});

it('orders the lines by item id whatever order they were made in, and names the reason by its code', function () {
    $low = min($this->flour->id, $this->sugar->id);
    $items = [$this->flour->id => $this->flour, $this->sugar->id => $this->sugar];
    $high = max(array_keys($items));
    $count = $this->chain->approved($this->place, [
        ['item' => $items[$high], 'counted' => '5', 'expected' => '5'],
        ['item' => $items[$low], 'counted' => '9', 'expected' => '10', 'reason' => 'miscount', 'note' => 'counted twice'],
    ]);

    $payload = app(PayloadBuilder::class)->build($count, 1, $this->lockedAt, $this->f->admin->id);

    expect(array_column($payload['lines'], 'item_id'))->toBe([$low, $high])
        ->and($payload['lines'][0]['reason_code'])->toBe('miscount')
        ->and($payload['lines'][0]['reason_note'])->toBe('counted twice')
        ->and($payload['lines'][1]['reason_code'])->toBeNull()
        ->and($payload['lines'][1]['variance_qty'])->toBe('0.0000', 'zero is never negative zero');
});

it('says what the correction was: the adjustment is the variance when a movement was written, null when not', function () {
    $count = $this->chain->approved($this->place, [
        ['item' => $this->flour, 'counted' => '9500', 'expected' => '10000', 'reason' => 'spoilage'],
        ['item' => $this->sugar, 'counted' => '300', 'expected' => '300'],
    ]);
    $movement = $this->f->ingredient();
    $this->f->stock($movement, $this->place, '1');
    $id = (int) DB::table('stock_movements')->orderByDesc('id')->value('id');
    DB::table('stock_count_lines')->where('count_id', $count->id)->where('item_id', $this->flour->id)->update(['adjustment_movement_id' => $id]);

    $payload = app(PayloadBuilder::class)->build($count, 1, $this->lockedAt, $this->f->admin->id);
    $byItem = collect($payload['lines'])->keyBy('item_id');

    expect($byItem[$this->flour->id]['adjustment_movement_id'])->toBe($id)
        ->and($byItem[$this->flour->id]['adjustment_qty'])->toBe('-500.0000')
        ->and($byItem[$this->sugar->id]['adjustment_movement_id'])->toBeNull()
        ->and($byItem[$this->sugar->id]['adjustment_qty'])->toBeNull();
});

it('names the count a revision supersedes', function () {
    $first = $this->chain->approved($this->place);
    $second = $this->chain->approved($this->place, [], ['seq' => 2]);
    DB::table('stock_counts')->where('id', $first->id)->update(['superseded_by_id' => $second->id, 'superseded_at' => now()]);

    $payload = app(PayloadBuilder::class)->build($second->fresh(), 2, $this->lockedAt, $this->f->admin->id);

    expect($payload['supersedes_count_id'])->toBe($first->id)
        ->and(app(PayloadBuilder::class)->build($first->fresh(), 1, $this->lockedAt, $this->f->admin->id)['supersedes_count_id'])->toBeNull();
});

it('gives the same bytes for the same rows every time, and different bytes when any figure moves', function () {
    $count = $this->chain->approved($this->place, [['item' => $this->flour, 'counted' => '9500', 'expected' => '10000', 'reason' => 'spoilage']]);
    $builder = app(PayloadBuilder::class);

    $once = $builder->canonical($count, 1, $this->lockedAt, $this->f->admin->id);
    $twice = $builder->canonical($count->fresh(), 1, $this->lockedAt, $this->f->admin->id);

    expect($once)->toBe($twice)
        ->and($once)->toBe(CanonicalJson::encode($builder->build($count, 1, $this->lockedAt, $this->f->admin->id)))
        ->and($builder->canonical($count, 2, $this->lockedAt, $this->f->admin->id))->not->toBe($once)
        ->and($builder->canonical($count, 1, $this->lockedAt->addMicrosecond(), $this->f->admin->id))->not->toBe($once)
        ->and($builder->canonical($count, 1, $this->lockedAt, $this->f->manager->id))->not->toBe($once);
});

it('rebuilds the payload of a locked count from its live rows to the exact bytes that were stored', function () {
    $links = $this->chain->chain($this->place, 2);

    foreach ($links as $link) {
        $stored = StockCount::query()->findOrFail($link->id);
        expect(app(PayloadBuilder::class)->rebuild($stored))->toBe($stored->canonical_payload);
    }
});

it('refuses a version it does not know', function () {
    $link = $this->chain->chain($this->place, 1)[0];
    $row = StockCount::query()->findOrFail($link->id);
    $row->payload_version = 9;

    expect(fn () => app(PayloadBuilder::class)->rebuild($row))->toThrow(LogicException::class);
});

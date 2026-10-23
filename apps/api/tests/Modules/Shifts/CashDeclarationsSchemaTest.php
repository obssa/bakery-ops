<?php

declare(strict_types=1);

use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'invariants');

/*
 * Migration 13 (phase-3 plan section 3): cash_declarations, one per cash-drawer count. Money columns are DECIMAL(12,2) named *_total, the
 * denominations and the channel figures are JSON, two CHECKs keep the money honest, and the row is frozen by trigger the way a count's
 * lines are: an INSERT or UPDATE is refused once the parent count is not a draft (and everything once it is locked), a DELETE always. The
 * freeze is also what lets the hash chain trust the row: a locked count's payload is rebuilt from it.
 */

const CASH_COLUMNS = [
    'id', 'branch_id', 'count_id', 'shift_instance_id', 'denominations', 'counted_total', 'expected_total', 'variance_total',
    'carried_float_total', 'cash_sales_total', 'float_left_total', 'channel_counts', 'variance_reason_id', 'variance_note', 'currency',
    'confirmed_at', 'created_at', 'updated_at',
];

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->drawer = $this->f->drawer($this->f->till);
    $this->start = $this->f->start($this->f->cashier, $this->f->till)->assertCreated();
    $this->count = (int) collect($this->start->json('counts'))->firstWhere('location.type', 'cash_drawer')['id'];
    $this->row = fn (): object => DB::table('cash_declarations')->where('count_id', $this->count)->first();
    $this->setStatus = function (string $status): void {
        $set = ['status' => $status, 'submitted_at' => now()];
        if ($status === 'locked') {
            $set += ['chain_seq' => 1, 'previous_hash' => str_repeat('0', 64), 'payload_hash' => str_repeat('a', 64), 'payload_version' => 1, 'canonical_payload' => '{}', 'locked_at' => now()];
        }
        DB::table('stock_counts')->where('id', $this->count)->update($set);
    };
});

it('has the table with exactly the columns of the plan (and the confirmation the submit needs)', function () {
    expect(Schema::hasTable('cash_declarations'))->toBeTrue()
        ->and(Schema::getColumnListing('cash_declarations'))->toEqualCanonicalizing(CASH_COLUMNS);
});

it('makes a draft count a declaration of nothing yet: zero counted, nothing confirmed, the branch and the shift', function () {
    $row = ($this->row)();

    expect($row)->not->toBeNull()
        ->and((string) $row->counted_total)->toBe('0.00')
        ->and($row->confirmed_at)->toBeNull()
        ->and($row->expected_total)->toBeNull()
        ->and((int) $row->branch_id)->toBe($this->branch->id)
        ->and((int) $row->shift_instance_id)->toBe((int) $this->start->json('shift.id'))
        ->and($row->currency)->toBe('ETB');
});

it('holds one declaration per count', function () {
    $row = (array) ($this->row)();
    unset($row['id']);

    expect(fn () => DB::table('cash_declarations')->insert($row))->toThrow(QueryException::class);
});

it('refuses a negative counted total and a float left that is negative or larger than the drawer', function (string $column, string $value) {
    expect(fn () => DB::table('cash_declarations')->where('count_id', $this->count)->update([$column => $value]))->toThrow(QueryException::class);
})->with([
    'counted below zero' => ['counted_total', '-1.00'],
    'float below zero' => ['float_left_total', '-0.01'],
    'float above counted (0.00)' => ['float_left_total', '0.01'],
]);

it('lets a draft declaration change and refuses every change once the count is handed in', function (string $status) {
    DB::table('cash_declarations')->where('count_id', $this->count)->update(['counted_total' => '10.00']);
    ($this->setStatus)($status);

    expect(fn () => DB::table('cash_declarations')->where('count_id', $this->count)->update(['counted_total' => '99.00']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('cash_declarations')->where('count_id', $this->count)->update(['variance_note' => 'later']))->toThrow(QueryException::class)
        ->and((string) ($this->row)()->counted_total)->toBe('10.00');
})->with(['awaiting_cosign', 'pending_approval', 'approved']);

it('refuses a change to a locked count\'s declaration, and every delete', function () {
    DB::table('cash_declarations')->where('count_id', $this->count)->update(['counted_total' => '10.00']);
    $draftDelete = fn () => DB::table('cash_declarations')->where('count_id', $this->count)->delete();
    expect($draftDelete)->toThrow(QueryException::class);

    ($this->setStatus)('locked');
    expect(fn () => DB::table('cash_declarations')->where('count_id', $this->count)->update(['counted_total' => '99.00']))->toThrow(QueryException::class)
        ->and($draftDelete)->toThrow(QueryException::class);
});

it('refuses a new declaration for a count that is not a draft', function () {
    $shift = ShiftInstance::query()->findOrFail((int) $this->start->json('shift.id'));
    $other = StockCount::factory()->forShift($shift, $this->drawer->id)->opening()->create();
    $row = (array) ($this->row)();
    unset($row['id']);
    $row['count_id'] = $other->id;
    DB::table('stock_counts')->where('id', $other->id)->update(['status' => 'approved', 'submitted_at' => now()]);

    expect(fn () => DB::table('cash_declarations')->insert($row))->toThrow(QueryException::class);
});

it('names every column of the table in the update trigger text (a column added later without the trigger fails here)', function () {
    if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('MariaDB trigger text');
    }
    $text = (string) ((array) DB::selectOne('SHOW CREATE TRIGGER `cash_declarations_freeze_update`'))['SQL Original Statement'];
    $columns = array_map(fn ($r) => (string) $r->name, DB::select('select column_name as name from information_schema.columns where table_schema = ? and table_name = ?', [DB::getDatabaseName(), 'cash_declarations']));

    foreach ($columns as $column) {
        if (in_array($column, ['updated_at'], true)) {
            continue;
        }
        expect(preg_match('/NEW\.`?'.preg_quote($column, '/').'`?\s*<=>/', $text))->toBe(1, "the update trigger names {$column}");
    }
});

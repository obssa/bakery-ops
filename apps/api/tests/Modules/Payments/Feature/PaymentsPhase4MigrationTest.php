<?php

declare(strict_types=1);

use App\Modules\Payments\Models\Payment;
use App\Shared\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

pest()->group('module:payments', 'feature');

/*
 * M4 extend_payments_for_phase_4 (phase-4 plan section 3, step 4.3b) runs on the live MariaDB the moment it merges, where every payment is
 * a Phase 1 cash row taken through RecordPayment (a few carry a reference typed at the till). Their meaning must not change: every
 * Phase 1 column keeps its value (updated_at included), refunded_amount is 0.00, nothing is attached, disputed or batched; the only
 * backfill is reference_norm, the normalised form of a reference that is already there (upper case, no spaces or dashes), so a Phase 4
 * duplicate check and the ?reference= filter see the old rows too. Two CHECKs land with it: amount > 0 and 0 <= refunded_amount <= amount.
 */

const M4_COLUMNS = ['batch_uuid', 'reference_norm', 'receipt_file_id', 'refunded_amount', 'disputed_by_user_id', 'disputed_at', 'dispute_reason', 'dispute_resolved_at'];

const PHASE_1_PAYMENT_COLUMNS = [
    'id', 'branch_id', 'client_uuid', 'order_id', 'channel_id', 'amount', 'tendered_amount', 'change_amount', 'currency', 'reference_no',
    'receipt_photo_key', 'status', 'received_by_user_id', 'acting_user_id', 'shift_instance_id', 'station_id', 'device_id', 'received_at',
    'verified_by_user_id', 'verified_at', 'voided_by_user_id', 'voided_at', 'void_reason', 'created_at', 'updated_at',
];

function paymentsPhase4Migration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_11_01_100100_extend_payments_for_phase_4.php');

    return $migration;
}

/** @return list<array<string, mixed>> */
function phaseOnePaymentRows(): array
{
    return DB::table('payments')->orderBy('id')->get(PHASE_1_PAYMENT_COLUMNS)->map(fn ($r) => (array) $r)->all();
}

/** @return array<string, object> column name => information_schema row */
function paymentColumns(): array
{
    $rows = DB::select(
        'select column_name as column_name, is_nullable as is_nullable, data_type as data_type, character_maximum_length as max_length,
                numeric_precision as num_precision, numeric_scale as num_scale, column_default as column_default
           from information_schema.columns where table_schema = ? and table_name = ?',
        [dbSchema(), 'payments'],
    );
    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row->column_name] = $row;
    }

    return $out;
}

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->cashier = makeUser($this->branch, 'cashier');
    withActor($this->cashier->id);
});

it('adds nullable columns, refunded_amount 0.00 by default, the reference index, the batch index and the photo\'s foreign key', function () {
    $columns = paymentColumns();

    foreach (M4_COLUMNS as $name) {
        expect($columns)->toHaveKey($name);
    }
    foreach (array_diff(M4_COLUMNS, ['refunded_amount']) as $name) {
        expect($columns[$name]->is_nullable)->toBe('YES', $name);
    }
    expect($columns['refunded_amount']->is_nullable)->toBe('NO')
        ->and([(int) $columns['refunded_amount']->num_precision, (int) $columns['refunded_amount']->num_scale])->toBe([12, 2])
        ->and(in_array($columns['refunded_amount']->column_default, ['0.00', "'0.00'", '0'], true))->toBeTrue((string) $columns['refunded_amount']->column_default)
        ->and((int) $columns['reference_norm']->max_length)->toBe(64)
        ->and((int) $columns['dispute_reason']->max_length)->toBe(255)
        ->and(Schema::hasIndex('payments', ['branch_id', 'channel_id', 'reference_norm']))->toBeTrue()
        ->and(Schema::hasIndex('payments', ['branch_id', 'batch_uuid']))->toBeTrue();
    $foreign = collect(Schema::getForeignKeys('payments'))->mapWithKeys(fn (array $fk): array => [implode(',', $fk['columns']) => $fk['foreign_table']])->all();
    expect($foreign['receipt_file_id'] ?? null)->toBe('stored_files')
        ->and($foreign['disputed_by_user_id'] ?? null)->toBe('users');

    $payment = Payment::factory()->create(['branch_id' => $this->branch->id]);
    expect(Payment::query()->findOrFail($payment->id)->refunded_amount->amount())->toBe('0.00');
});

it('refuses a payment of nothing and a refunded amount outside 0 to the amount, in the database', function () {
    $payment = Payment::factory()->create(['branch_id' => $this->branch->id, 'amount' => '100.00']);
    $set = fn (array $values) => DB::table('payments')->where('id', $payment->id)->update($values);

    foreach ([['amount' => '0.00'], ['amount' => '-1.00'], ['refunded_amount' => '100.01'], ['refunded_amount' => '-0.01']] as $values) {
        expect(fn () => $set($values))->toThrow(QueryException::class);
    }
    $set(['refunded_amount' => '100.00']);
    expect(DB::table('payments')->where('id', $payment->id)->value('refunded_amount'))->toBe('100.00');
});

it('runs on the Phase 1 payments and keeps their meaning; a second run is a no-op; a row it cannot hold stops it before any change', function () {
    // DDL commits the transaction RefreshDatabase wraps the test in (MariaDB, MySQL); RefreshDatabase sees that at the end and migrates
    // afresh before the next test, so what this test commits does not leak. It is the last test of the file on purpose.
    $migration = paymentsPhase4Migration();
    $migration->down();
    foreach (M4_COLUMNS as $name) {
        expect(Schema::hasColumn('payments', $name))->toBeFalse($name);
    }
    // what the till wrote in Phase 1: cash, one with a reference typed at the till, one tendered with change, one of another branch
    $other = Branch::factory()->create();
    $rows = [
        Payment::factory()->create(['branch_id' => $this->branch->id, 'reference_no' => 'ab-12 3', 'received_at' => '2026-10-20 08:00:00']),
        Payment::factory()->create(['branch_id' => $this->branch->id, 'amount' => '340.00', 'tendered_amount' => '500.00', 'change_amount' => '160.00']),
        Payment::factory()->create(['branch_id' => $other->id, 'reference_no' => 'Z9', 'received_by_user_id' => makeUser($other, 'cashier')->id]),
    ];
    DB::table('payments')->update(['updated_at' => '2026-10-20 09:00:00']);
    $before = phaseOnePaymentRows();
    expect($before)->toHaveCount(3);

    $migration->up();

    expect(phaseOnePaymentRows())->toBe($before);
    $after = DB::table('payments')->orderBy('id')->get()->keyBy('id');
    foreach ($after as $row) {
        expect($row->refunded_amount)->toBe('0.00')
            ->and([$row->batch_uuid, $row->receipt_file_id, $row->disputed_by_user_id, $row->disputed_at, $row->dispute_reason, $row->dispute_resolved_at])
            ->toBe([null, null, null, null, null, null]);
    }
    expect($after[$rows[0]->id]->reference_norm)->toBe('AB123')
        ->and($after[$rows[1]->id]->reference_norm)->toBeNull()
        ->and($after[$rows[2]->id]->reference_norm)->toBe('Z9');

    $columns = paymentColumns();
    $migration->up();
    expect(paymentColumns())->toEqual($columns)->and(phaseOnePaymentRows())->toBe($before);

    // a Phase 1 row of no money (the code never wrote one) would break the new CHECK: the migration names it and changes nothing
    $migration->down();
    DB::table('payments')->where('id', $rows[1]->id)->update(['amount' => '0.00']);
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, (string) $rows[1]->id);
    expect(Schema::hasColumn('payments', 'refunded_amount'))->toBeFalse()
        ->and(Schema::hasColumn('payments', 'reference_norm'))->toBeFalse();
    DB::table('payments')->where('id', $rows[1]->id)->update(['amount' => '340.00']);
    $migration->up();
    expect(Schema::hasColumn('payments', 'refunded_amount'))->toBeTrue();
});

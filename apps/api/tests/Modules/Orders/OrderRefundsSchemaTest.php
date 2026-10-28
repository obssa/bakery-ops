<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Location;
use App\Shared\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

pest()->group('module:orders', 'invariants');

/*
 * The refund tables of step 4.1b (phase-4 plan section 3, M7 and M8), asked of the real database: order_refunds (one row per refund: who
 * asked, whose PIN approved, why, how much, whether every line of the order came back) and order_refund_lines (one row per refunded line:
 * its quantity and money, the disposition its product's settings gave it, the reversing movements), both append-only through triggers;
 * a line is refunded once (UNIQUE order_line_id); payment_refunds.order_refund_id now points at order_refunds (4.1c left it a plain
 * column); orders.refunded_total (>= 0, a CHECK) and refunded_at, order_lines.refunded_at. Rows are written raw on purpose.
 */

/** @return object{data_type: string, p: ?int, s: ?int, nullable: string, dp: ?int, def: ?string}|null */
function refundColumn(string $table, string $column): ?object
{
    return DB::selectOne(
        'select data_type as data_type, numeric_precision as p, numeric_scale as s, is_nullable as nullable, datetime_precision as dp, column_default as def
           from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        [dbSchema(), $table, $column],
    );
}

/** @return list<string> */
function refundColumnNames(string $table): array
{
    return array_map(fn ($c) => (string) $c->name, DB::select(
        'select column_name as name from information_schema.columns where table_schema = ? and table_name = ? order by ordinal_position',
        [dbSchema(), $table],
    ));
}

function refundReferenced(string $table, string $column): ?string
{
    $row = DB::selectOne(
        'select referenced_table_name as ref from information_schema.key_column_usage
          where table_schema = ? and table_name = ? and column_name = ? and referenced_table_name is not null',
        [dbSchema(), $table, $column],
    );

    return $row === null ? null : (string) $row->ref;
}

/** @return array{branch: Branch, user: User, order: int, line: int, second: int} */
function refundParents(): array
{
    $branch = Branch::factory()->create();
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $item = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $branch->id, 'created_by_user_id' => $user->id]);
    $location = Location::factory()->create(['branch_id' => $branch->id]);
    $order = DB::table('orders')->insertGetId([
        'branch_id' => $branch->id, 'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'status' => 'closed', 'created_via' => 'till',
        'owner_user_id' => $user->id, 'created_by_user_id' => $user->id, 'currency' => 'ETB', 'subtotal' => '120.00', 'total' => '120.00',
        'paid_total' => '120.00', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $line = fn (): int => DB::table('order_lines')->insertGetId([
        'branch_id' => $branch->id, 'order_id' => $order, 'client_uuid' => (string) Str::uuid(), 'item_id' => $item->id, 'qty' => '1.0000',
        'unit_price_snapshot' => '60.00', 'modifier_price_snapshot' => '0.00', 'line_total' => '60.00', 'location_id' => $location->id,
        'reservation_ref' => (string) Str::uuid(), 'status' => 'served', 'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['branch' => $branch, 'user' => $user, 'order' => $order, 'line' => $line(), 'second' => $line()];
}

/** @param  array<string, mixed>  $over */
function rawRefund(array $p, array $over = []): array
{
    return $over + [
        'branch_id' => $p['branch']->id, 'client_uuid' => (string) Str::uuid(), 'order_id' => $p['order'], 'reason' => 'probe',
        'amount' => '60.00', 'is_full' => false, 'requested_by_user_id' => $p['user']->id, 'approved_by_user_id' => $p['user']->id,
        'created_at' => now(),
    ];
}

/** @param  array<string, mixed>  $over */
function rawRefundLine(array $p, int $refundId, array $over = []): array
{
    return $over + [
        'branch_id' => $p['branch']->id, 'order_refund_id' => $refundId, 'order_line_id' => $p['line'], 'qty' => '1.0000',
        'line_amount' => '60.00', 'disposition' => 'restock', 'movement_ids' => json_encode([]), 'created_at' => now(),
    ];
}

it('creates order_refunds and order_refund_lines with exactly the columns of the plan', function () {
    expect(refundColumnNames('order_refunds'))->toEqualCanonicalizing([
        'id', 'branch_id', 'client_uuid', 'order_id', 'reason', 'amount', 'is_full', 'requested_by_user_id', 'approved_by_user_id',
        'acting_user_id', 'shift_instance_id', 'station_id', 'device_id', 'created_at',
    ])->and(refundColumnNames('order_refund_lines'))->toEqualCanonicalizing([
        'id', 'branch_id', 'order_refund_id', 'order_line_id', 'qty', 'line_amount', 'disposition', 'movement_ids', 'created_at',
    ]);
});

it('gives both tables branch_id NOT NULL with a foreign key and an index, the money and quantity scales, and their links', function () {
    foreach (['order_refunds', 'order_refund_lines'] as $table) {
        expect(strtoupper((string) refundColumn($table, 'branch_id')?->nullable))->toBe('NO', "{$table}.branch_id")
            ->and(refundReferenced($table, 'branch_id'))->toBe('branches');
    }
    foreach (['order_refunds.amount' => '12,2', 'order_refund_lines.line_amount' => '12,2', 'order_refund_lines.qty' => '14,4', 'orders.refunded_total' => '12,2'] as $path => $scale) {
        [$table, $column] = explode('.', $path);
        $c = refundColumn($table, $column);
        expect(strtolower((string) $c?->data_type))->toBeIn(['decimal', 'numeric'], $path)
            ->and("{$c?->p},{$c?->s}")->toBe($scale, $path)
            ->and(strtoupper((string) $c?->nullable))->toBe('NO', $path);
    }
    $links = [
        'order_refunds.order_id' => 'orders', 'order_refunds.requested_by_user_id' => 'users', 'order_refunds.approved_by_user_id' => 'users',
        'order_refunds.acting_user_id' => 'users', 'order_refunds.shift_instance_id' => 'shift_instances', 'order_refunds.station_id' => 'stations',
        'order_refunds.device_id' => 'devices', 'order_refund_lines.order_refund_id' => 'order_refunds', 'order_refund_lines.order_line_id' => 'order_lines',
        'payment_refunds.order_refund_id' => 'order_refunds',
    ];
    foreach ($links as $path => $target) {
        [$table, $column] = explode('.', $path);
        expect(refundReferenced($table, $column))->toBe($target, $path);
    }
    expect(strtoupper((string) refundColumn('orders', 'refunded_at')?->nullable))->toBe('YES')
        ->and(strtoupper((string) refundColumn('order_lines', 'refunded_at')?->nullable))->toBe('YES')
        ->and(Schema::hasColumns('order_refunds', ['updated_at']))->toBeFalse()
        ->and(Schema::hasColumns('order_refund_lines', ['updated_at', 'deleted_at']))->toBeFalse();
});

it('refunds a line once, keeps client_uuid unique per branch, and constrains the money, the disposition and refunded_total', function () {
    $p = refundParents();
    $refund = DB::table('order_refunds')->insertGetId(rawRefund($p));
    DB::table('order_refund_lines')->insert(rawRefundLine($p, $refund));

    expect(fn () => DB::table('order_refund_lines')->insert(rawRefundLine($p, $refund)))->toThrow(QueryException::class, 'order_line_id')
        ->and(fn () => DB::table('order_refunds')->insert(rawRefund($p, ['client_uuid' => DB::table('order_refunds')->where('id', $refund)->value('client_uuid')])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('order_refunds')->insert(rawRefund($p, ['amount' => '0.00'])))->toThrow(QueryException::class, 'order_refunds_amount_positive')
        ->and(fn () => DB::table('order_refund_lines')->insert(rawRefundLine($p, $refund, ['order_line_id' => $p['second'], 'disposition' => 'none'])))->toThrow(QueryException::class, 'order_refund_lines_disposition_check')
        ->and(fn () => DB::table('order_refund_lines')->insert(rawRefundLine($p, $refund, ['order_line_id' => $p['second'], 'line_amount' => '-1.00'])))->toThrow(QueryException::class, 'order_refund_lines_line_amount_check')
        ->and(fn () => DB::table('order_refund_lines')->insert(rawRefundLine($p, $refund, ['order_line_id' => $p['second'], 'qty' => '0.0000'])))->toThrow(QueryException::class, 'order_refund_lines_qty_positive')
        ->and(fn () => DB::table('orders')->where('id', $p['order'])->update(['refunded_total' => '-1.00']))->toThrow(QueryException::class, 'orders_refunded_total_check');

    DB::table('order_refund_lines')->insert(rawRefundLine($p, $refund, ['order_line_id' => $p['second'], 'disposition' => 'waste']));
    // another branch may reuse a client_uuid
    $q = refundParents();
    DB::table('order_refunds')->insert(rawRefund($q, ['client_uuid' => DB::table('order_refunds')->where('id', $refund)->value('client_uuid')]));
    expect(DB::table('order_refund_lines')->count())->toBe(2)
        ->and((string) DB::table('orders')->where('id', $p['order'])->value('refunded_total'))->toBe('0.00', 'every existing order starts at zero');
});

it('keeps both tables append-only through triggers', function () {
    $p = refundParents();
    $refund = DB::table('order_refunds')->insertGetId(rawRefund($p));
    $line = DB::table('order_refund_lines')->insertGetId(rawRefundLine($p, $refund));

    foreach (['order_refunds' => [$refund, ['reason' => 'tampered']], 'order_refund_lines' => [$line, ['disposition' => 'waste']]] as $table => [$id, $change]) {
        $triggers = DB::select('select trigger_name as name from information_schema.triggers where trigger_schema = ? and event_object_table = ?', [dbSchema(), $table]);
        expect(collect($triggers)->pluck('name')->every(fn (string $n): bool => str_starts_with($n, "{$table}_forbid_update_delete")))->toBeTrue($table)
            ->and($triggers)->not->toBeEmpty()
            ->and(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->update($change)))->toThrow(QueryException::class, 'append-only')
            ->and(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))->toThrow(QueryException::class, 'append-only')
            ->and(DB::table($table)->where('id', $id)->exists())->toBeTrue();
    }
});

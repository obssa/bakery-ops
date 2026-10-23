<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Location;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * references/invariants.md, "Append-only": stock_movements, audit_log and order_events reject UPDATE and DELETE at
 * the database level, through triggers created in their migrations (MySQL: BEFORE UPDATE / BEFORE DELETE triggers
 * that SIGNAL SQLSTATE '45000'; PostgreSQL: forbid_update_delete()). The list of tables is read from
 * scripts/check_invariants.py so the host-side lint and this database test cannot drift apart. Every attempt runs
 * in its own savepoint: on PostgreSQL a failed statement aborts the surrounding transaction otherwise.
 */

function appendOnlyTables(): array
{
    $python = (string) file_get_contents(dirname(__DIR__, 4).'/scripts/check_invariants.py');
    preg_match('/APPEND_ONLY_TABLES\s*=\s*\[(.*?)\]/s', $python, $m);
    preg_match_all('/"(\w+)"/', $m[1] ?? '', $names);

    return $names[1];
}

/** One minimal row per append-only table, so UPDATE/DELETE have something to hit. Extend as tables land. */
function seedAppendOnlyRow(string $table): int
{
    return match ($table) {
        'audit_log' => DB::table('audit_log')->insertGetId([
            'branch_id' => DB::table('branches')->insertGetId(['name' => 'Append-only probe', 'timezone' => 'Africa/Addis_Ababa', 'currency' => 'ETB', 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()]),
            'actor_id' => 1, 'action' => 'probe', 'subject_type' => 'probe', 'subject_id' => 1,
            'before' => null, 'after' => json_encode(['n' => 1]), 'at' => now(),
        ]),
        'stock_movements' => (function (): int {
            $branch = Branch::factory()->create();
            $user = User::factory()->create(['branch_id' => $branch->id]);

            return DB::table('stock_movements')->insertGetId([
                'branch_id' => $branch->id,
                'item_id' => Item::factory()->ingredient('g')->create(['branch_id' => $branch->id, 'created_by_user_id' => $user->id])->id,
                'to_location_id' => Location::factory()->create(['branch_id' => $branch->id])->id,
                'qty' => '1.0000', 'reason' => 'purchase_in', 'ref_type' => 'probe', 'user_id' => $user->id, 'created_at' => now(),
            ]);
        })(),
        'order_events' => (function (): int {
            $branch = Branch::factory()->create();
            $user = User::factory()->create(['branch_id' => $branch->id]);
            $orderId = DB::table('orders')->insertGetId([
                'branch_id' => $branch->id, 'client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'created_via' => 'till',
                'owner_user_id' => $user->id, 'created_by_user_id' => $user->id, 'currency' => 'ETB',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('order_events')->insertGetId([
                'branch_id' => $branch->id, 'order_id' => $orderId, 'owner_user_id' => $user->id, 'event' => 'order.created',
                'user_id' => $user->id, 'payload' => json_encode(['probe' => true]), 'created_at' => now(),
            ]);
        })(),
        'item_cost_history' => (function (): int {
            $branch = Branch::factory()->create();
            $user = User::factory()->create(['branch_id' => $branch->id]);

            return DB::table('item_cost_history')->insertGetId([
                'branch_id' => $branch->id,
                'item_id' => Item::factory()->ingredient('g')->create(['branch_id' => $branch->id, 'created_by_user_id' => $user->id])->id,
                'source' => 'purchase', 'purchase_line_id' => null, 'qty_before' => '0.0000', 'cost_before' => null, 'qty_in' => '1.0000',
                'unit_cost_in' => '5.0000', 'cost_after' => '5.0000', 'user_id' => $user->id, 'acting_user_id' => null, 'created_at' => now(),
            ]);
        })(),
        'handover_notes' => (function (): int {
            $branch = Branch::factory()->create();
            $user = User::factory()->create(['branch_id' => $branch->id]);
            $shiftId = DB::table('shift_instances')->insertGetId([
                'branch_id' => $branch->id, 'client_uuid' => (string) Str::uuid(), 'station_id' => Station::factory()->create(['branch_id' => $branch->id])->id,
                'slot_key' => 0, 'sequence' => 1, 'date' => '2026-11-02', 'origin' => 'started', 'status' => 'scheduled', 'created_by_user_id' => $user->id,
                'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('handover_notes')->insertGetId([
                'branch_id' => $branch->id, 'client_uuid' => (string) Str::uuid(), 'shift_instance_id' => $shiftId, 'from_user_id' => $user->id,
                'body' => 'Probe', 'created_by_user_id' => $user->id, 'created_at' => now(),
            ]);
        })(),
        default => throw new LogicException("seedAppendOnlyRow(): add a seed row for append-only table {$table}"),
    };
}

/** A change to a column the table really has, so the trigger (not an unknown column) is what refuses it. */
function appendOnlyTamper(string $table): array
{
    return match ($table) {
        'stock_movements' => ['qty' => '2.0000'],
        'order_events' => ['event' => 'order.voided'],
        'item_cost_history' => ['cost_after' => '9.0000'],
        'handover_notes' => ['body' => 'tampered'],
        default => ['action' => 'tampered'],
    };
}

function triggerEvents(string $table): array
{
    return array_map(fn ($t) => strtoupper((string) $t->event), DB::select(
        'select event_manipulation as event from information_schema.triggers where trigger_schema = ? and event_object_table = ?',
        [dbSchema(), $table],
    ));
}

it('names the append-only tables in scripts/check_invariants.py', function () {
    expect(appendOnlyTables())->toContain('audit_log', 'stock_movements', 'order_events', 'item_cost_history');
})->group('invariants');

it('rejects UPDATE and DELETE on every append-only table that exists, at the database level', function () {
    $checked = 0;
    foreach (appendOnlyTables() as $table) {
        if (! Schema::hasTable($table)) {
            continue; // arrives with its module (stock_movements 0.6, order_events 1.3a, item_cost_history 2.2a)
        }
        $checked++;
        $id = seedAppendOnlyRow($table);

        expect(triggerEvents($table))->toContain('UPDATE', 'DELETE');

        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->update(appendOnlyTamper($table))))
            ->toThrow(QueryException::class, 'append-only');
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))
            ->toThrow(QueryException::class, 'append-only');
        expect(fn () => DB::transaction(fn () => DB::statement("DELETE FROM {$table}")))
            ->toThrow(QueryException::class);

        expect(DB::table($table)->where('id', $id)->exists())->toBeTrue("{$table} row {$id} must survive");
    }
    expect($checked)->toBeGreaterThan(0);
})->group('invariants');

it('has no updated_at or deleted_at on append-only tables: a row is written once', function () {
    foreach (appendOnlyTables() as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }
        expect(Schema::hasColumns($table, ['updated_at']))->toBeFalse("{$table}.updated_at")
            ->and(Schema::hasColumns($table, ['deleted_at']))->toBeFalse("{$table}.deleted_at");
    }
})->group('invariants');

<?php

declare(strict_types=1);

use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'invariants');

/*
 * Migration 12 (phase-3 plan section 3): a locked stock_counts row is the chain link, and the database refuses any UPDATE of it except
 * superseded_by_id (NULL to a value, once) and its twin superseded_at; no count is ever deleted. The column list is read from
 * information_schema, so a column added later is covered automatically: it is refused unless the migration's trigger is updated to
 * allow it on purpose.
 */

const SUPERSEDE_COLUMNS = ['superseded_by_id', 'superseded_at'];

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->place = $this->f->place($this->f->kitchen);
    $this->shift = fn (): ShiftInstance => ShiftInstance::factory()->locked()->create([
        'branch_id' => $this->branch->id, 'station_id' => $this->f->kitchen->id, 'created_by_user_id' => $this->f->chef->id,
        'date' => now()->addDays(random_int(1, 5000))->toDateString(),
    ]);
    $this->locked = fn (): StockCount => StockCount::factory()->forShift(($this->shift)(), $this->place->id)->closing()->status(CountStatus::Locked)->create(['created_by_user_id' => $this->f->chef->id]);
    $this->row = fn (int $id) => DB::table('stock_counts')->where('id', $id)->first();
});

/** @return array<string, array{type: string, nullable: bool}> */
function stockCountColumns(): array
{
    $columns = [];
    foreach (DB::select('select column_name as name, data_type as type, is_nullable as nullable from information_schema.columns where table_schema = ? and table_name = ?', [dbSchema(), 'stock_counts']) as $row) {
        $columns[$row->name] = ['type' => strtolower((string) $row->type), 'nullable' => $row->nullable === 'YES'];
    }

    return $columns;
}

it('refuses a change to any column of a locked count, one column at a time, except the two supersede columns', function () {
    $count = ($this->locked)();
    $failures = [];

    foreach (stockCountColumns() as $column => $info) {
        if (in_array($column, SUPERSEDE_COLUMNS, true)) {
            continue;
        }
        $old = ($this->row)($count->id)->{$column};
        $new = match (true) {
            in_array($info['type'], ['int', 'bigint', 'smallint', 'tinyint', 'mediumint'], true) => ((int) $old) + 1,
            in_array($info['type'], ['datetime', 'timestamp'], true) => '2001-01-01 00:00:00',
            $column === 'client_uuid' => (string) Str::uuid(),
            // the same length, one character different (a longer value would be refused as too long before the trigger sees it)
            default => $old === null ? 'x' : (((string) $old)[0] === 'z' ? 'y' : 'z').substr((string) $old, 1),
        };
        if ($column === 'previous_hash' || $column === 'payload_hash') {
            $new = str_repeat('c', 64);
        }
        try {
            DB::table('stock_counts')->where('id', $count->id)->update([$column => $new]);
            $failures[] = "{$column}: the update of a locked count went through";
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'locked count is immutable')) {
                $failures[] = "{$column}: refused, but not by the trigger: ".$e->getMessage();
            }
        }
        // and a change to NULL where the column may be null
        if ($info['nullable'] && $old !== null) {
            try {
                DB::table('stock_counts')->where('id', $count->id)->update([$column => null]);
                $failures[] = "{$column}: setting it to NULL went through";
            } catch (QueryException $e) {
                if (! str_contains($e->getMessage(), 'locked count is immutable')) {
                    $failures[] = "{$column} to NULL: refused, but not by the trigger: ".$e->getMessage();
                }
            }
        }
    }

    expect($failures)->toBe([]);
});

it('refuses the status of a locked count moving to any other status', function (string $to) {
    $count = ($this->locked)();

    expect(fn () => DB::table('stock_counts')->where('id', $count->id)->update(['status' => $to]))->toThrow(QueryException::class, 'locked count is immutable');
})->with(['draft', 'approved', 'rejected']);

it('lets a locked count be superseded once: superseded_by_id from NULL to a value, with superseded_at', function () {
    $old = ($this->locked)();
    $revision = StockCount::factory()->forShift(ShiftInstance::query()->findOrFail($old->shift_instance_id), $this->place->id)->closing()->create(['created_by_user_id' => $this->f->chef->id]);
    $other = StockCount::factory()->forShift(ShiftInstance::query()->findOrFail($old->shift_instance_id), $this->place->id)->closing()->create(['created_by_user_id' => $this->f->chef->id]);

    DB::table('stock_counts')->where('id', $old->id)->update(['superseded_by_id' => $revision->id, 'superseded_at' => '2026-11-03 09:00:00']);

    $row = ($this->row)($old->id);
    expect((int) $row->superseded_by_id)->toBe($revision->id)
        ->and($row->superseded_at)->toBe('2026-11-03 09:00:00')
        ->and($row->status)->toBe('locked');
    expect(fn () => DB::table('stock_counts')->where('id', $old->id)->update(['superseded_by_id' => $other->id]))->toThrow(QueryException::class, 'locked count is immutable')
        ->and(fn () => DB::table('stock_counts')->where('id', $old->id)->update(['superseded_by_id' => null]))->toThrow(QueryException::class, 'locked count is immutable')
        ->and(fn () => DB::table('stock_counts')->where('id', $old->id)->update(['superseded_at' => '2026-11-04 09:00:00']))->toThrow(QueryException::class, 'locked count is immutable')
        ->and(fn () => DB::table('stock_counts')->where('id', $old->id)->update(['superseded_at' => null]))->toThrow(QueryException::class, 'locked count is immutable');
});

it('does not let the supersede columns carry another change with them', function () {
    $old = ($this->locked)();
    $revision = StockCount::factory()->forShift(ShiftInstance::query()->findOrFail($old->shift_instance_id), $this->place->id)->closing()->create(['created_by_user_id' => $this->f->chef->id]);

    expect(fn () => DB::table('stock_counts')->where('id', $old->id)->update(['superseded_by_id' => $revision->id, 'decision_note' => 'sneaked in']))
        ->toThrow(QueryException::class, 'locked count is immutable');
    expect((int) (($this->row)($old->id)->superseded_by_id))->toBe(0);
});

it('still lets a count that is not locked change, and become locked', function () {
    $count = StockCount::factory()->forShift(($this->shift)(), $this->place->id)->closing()->status(CountStatus::Approved)->create(['created_by_user_id' => $this->f->chef->id]);

    DB::table('stock_counts')->where('id', $count->id)->update(['decision_note' => 'fine']);
    DB::table('stock_counts')->where('id', $count->id)->update([
        'status' => 'locked', 'chain_seq' => 1, 'previous_hash' => str_repeat('0', 64), 'payload_hash' => str_repeat('a', 64), 'payload_version' => 1,
        'canonical_payload' => '{}', 'locked_at' => '2026-11-02 12:00:00.000000',
    ]);

    expect(($this->row)($count->id)->status)->toBe('locked');
});

it('never deletes a count, locked or not', function () {
    $locked = ($this->locked)();
    $draft = StockCount::factory()->forShift(($this->shift)(), $this->place->id)->closing()->create(['created_by_user_id' => $this->f->chef->id]);

    expect(fn () => DB::table('stock_counts')->where('id', $locked->id)->delete())->toThrow(QueryException::class, 'never deleted')
        ->and(fn () => DB::table('stock_counts')->where('id', $draft->id)->delete())->toThrow(QueryException::class, 'never deleted')
        ->and(DB::table('stock_counts')->whereIn('id', [$locked->id, $draft->id])->count())->toBe(2);
});

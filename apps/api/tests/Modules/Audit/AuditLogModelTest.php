<?php

declare(strict_types=1);

use App\Modules\Audit\AuditLogIsAppendOnly;
use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Audit\Models\AuditLog;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/*
 * The AuditLog model is read-mostly: rows come from AuditedAction, are scoped to the branch like every tenant
 * model, and can never be changed through Eloquent (the database trigger is the second line, tests/Invariants).
 */

beforeEach(function () {
    $this->a = Branch::factory()->create();
    $this->b = Branch::factory()->create();
    withActor(1);

    actInBranch($this->a);
    app(AuditedAction::class)->recordEvent('a.event', 'thing', 1, ['n' => 1]);
    actInBranch($this->b);
    app(AuditedAction::class)->recordEvent('b.event', 'thing', 2, ['n' => 2]);
});

it('is branch scoped like every tenant model', function () {
    actInBranch($this->a);

    expect(AuditLog::query()->pluck('action')->all())->toBe(['a.event']);

    app(CurrentBranch::class)->clear();
    expect(AuditLog::query()->count())->toBe(2);
})->group('module:audit', 'feature');

it('refuses update, save-after-change and delete through Eloquent', function () {
    actInBranch($this->a);
    $row = AuditLog::query()->firstOrFail();

    expect(fn () => $row->update(['action' => 'tampered']))->toThrow(AuditLogIsAppendOnly::class);

    $row->action = 'tampered';
    expect(fn () => $row->save())->toThrow(AuditLogIsAppendOnly::class);
    expect(fn () => $row->delete())->toThrow(AuditLogIsAppendOnly::class);

    expect(DB::table('audit_log')->where('id', $row->id)->value('action'))->toBe('a.event')
        ->and(DB::table('audit_log')->count())->toBe(2);
})->group('module:audit', 'feature');

it('has no timestamps beyond at and never soft-deletes', function () {
    $row = AuditLog::query()->withoutGlobalScopes()->firstOrFail();

    expect($row->timestamps)->toBeFalse()
        ->and(in_array(SoftDeletes::class, class_uses_recursive(AuditLog::class), true))->toBeFalse()
        ->and(array_keys($row->getAttributes()))->not->toContain('created_at', 'updated_at', 'deleted_at');
})->group('module:audit', 'feature');

it('has the columns and indexes the domain model asks for', function () {
    $columns = array_map(fn ($c) => $c->column_name, DB::select(
        'select column_name as column_name from information_schema.columns where table_schema = ? and table_name = ? order by ordinal_position',
        [dbSchema(), 'audit_log'],
    ));

    expect($columns)->toBe([
        'id', 'branch_id', 'actor_id', 'acting_user_id', 'action', 'subject_type', 'subject_id',
        'before', 'after', 'context', 'shift_instance_id', 'station_id', 'device_id', 'ip', 'at',
    ]);

    $indexes = DB::connection()->getDriverName() === 'pgsql'
        ? array_map(fn ($i) => $i->indexname, DB::select("select indexname from pg_indexes where schemaname = 'public' and tablename = 'audit_log'"))
        : array_map(fn ($i) => $i->index_name, DB::select('select distinct index_name as index_name from information_schema.statistics where table_schema = ? and table_name = ?', [dbSchema(), 'audit_log']));

    expect($indexes)->toContain('audit_log_branch_id_at_index', 'audit_log_subject_type_subject_id_index');
})->group('module:audit', 'feature');

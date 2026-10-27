<?php

declare(strict_types=1);

use App\Shared\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AuditRows;

/*
 * M17 (phase-4 plan section 3, step 4.5a): the read indexes of the audit-log screen's filters, (branch_id, id) for the newest-first page,
 * (branch_id, action, id), (branch_id, subject_type, subject_id, id), (branch_id, actor_id, id) and (branch_id, acting_user_id, id) for the
 * person's filter, which looks at both columns. Safe on the live table, which holds real rows since Phase 0 and is append-only: the
 * migration adds and drops indexes only, so no row is touched and the triggers stay.
 */

pest()->group('module:audit', 'feature');

function auditReadIndexesMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_11_08_100100_add_audit_log_read_indexes.php');

    return $migration;
}

/** @return list<list<string>> the column lists of audit_log's indexes */
function auditLogIndexColumns(): array
{
    return array_map(fn (array $index): array => $index['columns'], Schema::getIndexes('audit_log'));
}

it('has the read indexes of the screen\'s filters', function () {
    expect(auditLogIndexColumns())->toContain(
        ['branch_id', 'id'],
        ['branch_id', 'action', 'id'],
        ['branch_id', 'subject_type', 'subject_id', 'id'],
        ['branch_id', 'actor_id', 'id'],
        ['branch_id', 'acting_user_id', 'id'],
        ['branch_id', 'at'],
        ['subject_type', 'subject_id'],
    );
});

it('only adds and drops indexes: the rows stay byte for byte and the append-only triggers stay', function () {
    $branch = Branch::factory()->create();
    $ids = AuditRows::many($branch->id, 1, 3);
    $rows = fn (): array => DB::table('audit_log')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    $before = $rows();

    auditReadIndexesMigration()->down();
    expect(auditLogIndexColumns())->not->toContain(['branch_id', 'action', 'id'])
        ->and($rows())->toBe($before);
    auditReadIndexesMigration()->up();

    expect($rows())->toBe($before)
        ->and(auditLogIndexColumns())->toContain(['branch_id', 'action', 'id'])
        ->and(fn () => DB::table('audit_log')->where('id', $ids[0])->update(['action' => 'rewritten']))->toThrow(Exception::class)
        ->and(fn () => DB::table('audit_log')->where('id', $ids[0])->delete())->toThrow(Exception::class);
});

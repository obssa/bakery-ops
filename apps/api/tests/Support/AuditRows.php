<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Audit\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * audit_log rows for the read API's tests (step 4.5a), written straight into the table the way AuditedAction leaves them, or the way an
 * older AuditedAction left them: write() stores before, after and context exactly as given (no Snapshot), so a test can plant a row that was
 * written before a redaction rule existed and prove the read API still hides what it holds. The table is append-only (triggers refuse an
 * update or a delete), so a row is only ever inserted. `at` is UTC, as the column stores it.
 */
final class AuditRows
{
    /**
     * One row of $branchId. Defaults: actor $actorId, action `thing.update`, subject `thing` 1, no snapshots, no stamp, now.
     *
     * @param  array<string, mixed>  $attributes  any audit_log column; before / after / context are arrays
     */
    public static function write(int $branchId, int $actorId, array $attributes = []): AuditLog
    {
        $row = new AuditLog;
        $row->forceFill($attributes + [
            'branch_id' => $branchId,
            'actor_id' => $actorId,
            'acting_user_id' => null,
            'action' => 'thing.update',
            'subject_type' => 'thing',
            'subject_id' => 1,
            'before' => null,
            'after' => null,
            'context' => null,
            'shift_instance_id' => null,
            'station_id' => null,
            'device_id' => null,
            'ip' => null,
            'at' => CarbonImmutable::now('UTC'),
        ]);
        $row->save();

        return $row;
    }

    /**
     * $count plain rows of $branchId in one statement, oldest first; their ids ascending.
     *
     * @return list<int>
     */
    public static function many(int $branchId, int $actorId, int $count, string $action = 'thing.update'): array
    {
        $at = CarbonImmutable::now('UTC')->subMinutes($count)->format('Y-m-d H:i:s');
        $before = (int) (DB::table('audit_log')->max('id') ?? 0);
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'branch_id' => $branchId, 'actor_id' => $actorId, 'action' => $action, 'subject_type' => 'thing', 'subject_id' => $i,
                'at' => $at,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('audit_log')->insert($chunk);
        }

        return array_map(intval(...), DB::table('audit_log')->where('id', '>', $before)->orderBy('id')->pluck('id')->all());
    }

    /**
     * The stored JSON of a row, read past the model (no cast, no redaction): what is really in the table.
     *
     * @return array{before: string|null, after: string|null, context: string|null}
     */
    public static function raw(int $id): array
    {
        $row = DB::table('audit_log')->where('id', $id)->first(['before', 'after', 'context']);

        return [
            'before' => $row?->before === null ? null : (string) $row->before,
            'after' => $row?->after === null ? null : (string) $row->after,
            'context' => $row?->context === null ? null : (string) $row->context,
        ];
    }
}

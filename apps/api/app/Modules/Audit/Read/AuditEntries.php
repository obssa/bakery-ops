<?php

declare(strict_types=1);

namespace App\Modules\Audit\Read;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Snapshot;
use App\Shared\Contracts\ActorNames;

/**
 * An audit row as the read API shows it (phase-4 plan section 4.7, step 4.5a):
 *   {id, at (UTC, ISO 8601), actor {id, name}, acting_user {id, name} or null, action, subject_type, subject_id, station_id, shift_id,
 *    device_id, ip, summary {changed, more, reason}}, and for one row by id also before, after and context.
 * before, after and context pass through Snapshot's rules on every read, so a row written before a rule existed shows nothing a rule hides
 * today (audit_log is append-only: an old row is never rewritten). The summary is made from those redacted values: the fields whose value
 * differs between before and after (a field missing on one side is null there), without the bookkeeping fields id, branch_id, created_at
 * and updated_at, sorted, at most SUMMARY_FIELDS of them with the count of the rest; and the context's reason when it has one. A redacted
 * field shows as changed only when it went from null to set or back. Names come only through Shared's ActorNames (Identity binds it): one
 * call for a whole page; an id nobody of the branch has is named null.
 */
final class AuditEntries
{
    public const int SUMMARY_FIELDS = 10;

    /** @var list<string> */
    private const array BOOKKEEPING = ['id', 'branch_id', 'created_at', 'updated_at'];

    public function __construct(private readonly ActorNames $names) {}

    /**
     * @param  list<AuditLog>  $rows
     * @return list<array<string, mixed>>
     */
    public function list(array $rows): array
    {
        $names = $this->namesOf($rows);

        return array_map(fn (AuditLog $row): array => $this->row($row, $names, self::redacted($row)), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(AuditLog $row): array
    {
        $redacted = self::redacted($row);

        return $this->row($row, $this->namesOf([$row]), $redacted) + $redacted;
    }

    /**
     * The names of the actors and acting users of $rows, asked once.
     *
     * @param  list<AuditLog>  $rows
     * @return array<int, string>
     */
    public function namesOf(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row->actor_id;
            if ($row->acting_user_id !== null) {
                $ids[] = $row->acting_user_id;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids === [] ? [] : $this->names->names($ids);
    }

    /**
     * before, after and context through Snapshot's rules, as every read shows them.
     *
     * @return array{before: array<array-key, mixed>|null, after: array<array-key, mixed>|null, context: array<array-key, mixed>|null}
     */
    public static function redacted(AuditLog $row): array
    {
        return [
            'before' => is_array($row->before) ? Snapshot::redact($row->before) : null,
            'after' => is_array($row->after) ? Snapshot::redact($row->after) : null,
            'context' => is_array($row->context) ? Snapshot::redact($row->context) : null,
        ];
    }

    /**
     * Every field whose value differs between before and after, sorted, without the bookkeeping fields.
     *
     * @param  array<array-key, mixed>|null  $before
     * @param  array<array-key, mixed>|null  $after
     * @return list<string>
     */
    public static function changed(?array $before, ?array $after): array
    {
        $before ??= [];
        $after ??= [];
        $changed = [];
        foreach (array_unique(array_map(strval(...), array_merge(array_keys($before), array_keys($after)))) as $field) {
            if (! in_array($field, self::BOOKKEEPING, true) && ($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changed[] = $field;
            }
        }
        sort($changed, SORT_STRING);

        return $changed;
    }

    /**
     * The context's reason (a void, a dispute, an unlock), when it is a text.
     *
     * @param  array<array-key, mixed>|null  $context
     */
    public static function reason(?array $context): ?string
    {
        $reason = $context['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * @param  array<int, string>  $names
     * @param  array{before: array<array-key, mixed>|null, after: array<array-key, mixed>|null, context: array<array-key, mixed>|null}  $redacted
     * @return array<string, mixed>
     */
    private function row(AuditLog $row, array $names, array $redacted): array
    {
        $changed = self::changed($redacted['before'], $redacted['after']);

        return [
            'id' => $row->id,
            'at' => $row->at->toIso8601String(),
            'actor' => ['id' => $row->actor_id, 'name' => $names[$row->actor_id] ?? null],
            'acting_user' => $row->acting_user_id === null ? null : ['id' => $row->acting_user_id, 'name' => $names[$row->acting_user_id] ?? null],
            'action' => $row->action,
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id,
            'station_id' => $row->station_id,
            'shift_id' => $row->shift_instance_id,
            'device_id' => $row->device_id,
            'ip' => $row->ip,
            'summary' => [
                'changed' => array_slice($changed, 0, self::SUMMARY_FIELDS),
                'more' => max(0, count($changed) - self::SUMMARY_FIELDS),
                'reason' => self::reason($redacted['context']),
            ],
        ];
    }
}

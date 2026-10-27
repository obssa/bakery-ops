<?php

declare(strict_types=1);

namespace App\Modules\Audit\Read;

use App\Modules\Audit\Models\AuditLog;
use App\Shared\Time\LocalDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The branch's audit rows as GET /audit-log filters them (phase-4 plan section 4.7, step 4.5a), newest first by id, keyset paged (`cursor`
 * = the last id seen; the next page is the ids below it). AuditLog is branch scoped, so another branch's rows are never read. The filters,
 * each read by an index of M17:
 *   from / to       local dates of `at` in the branch's zone, both ends inclusive (LocalDay: 23:59:59 in Addis Ababa is still that day);
 *   actor_id        the person, as the account signed in (actor_id) or as the person acting by short code at a shared pad (acting_user_id);
 *   action          a prefix taken literally (a `%` or a `_` in it is only that character);
 *   subject_type, subject_id, station_id, shift_id (the row's shift_instance_id)   equal.
 */
final class AuditLogQuery
{
    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 200;

    /**
     * @param  array<string, string>  $filters  the validated query (ListAuditLogRequest::filters)
     * @return Builder<AuditLog>
     */
    public function query(array $filters): Builder
    {
        $query = AuditLog::query();
        if (isset($filters['from'])) {
            $query->where('audit_log.at', '>=', self::utc(LocalDay::of($filters['from'])->utcBounds()[0]));
        }
        if (isset($filters['to'])) {
            $query->where('audit_log.at', '<', self::utc(LocalDay::of($filters['to'])->utcBounds()[1]));
        }
        if (isset($filters['actor_id'])) {
            $person = (int) $filters['actor_id'];
            $query->where(fn (Builder $who) => $who->where('audit_log.actor_id', $person)->orWhere('audit_log.acting_user_id', $person));
        }
        if (isset($filters['action'])) {
            // `!` escapes, the same on MariaDB and PostgreSQL whatever their backslash settings
            $query->whereRaw("audit_log.action LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['action']).'%']);
        }
        foreach (['subject_type' => 'subject_type', 'subject_id' => 'subject_id', 'station_id' => 'station_id', 'shift_id' => 'shift_instance_id'] as $key => $column) {
            if (isset($filters[$key])) {
                $query->where('audit_log.'.$column, $key === 'subject_type' ? $filters[$key] : (int) $filters[$key]);
            }
        }

        return $query;
    }

    /**
     * One page, newest first: the rows and the cursor of the next page (null on the last one).
     *
     * @param  array<string, string>  $filters
     * @return array{0: list<AuditLog>, 1: string|null}
     */
    public function page(array $filters): array
    {
        $limit = isset($filters['limit']) ? max(1, min(self::MAX_LIMIT, (int) $filters['limit'])) : self::DEFAULT_LIMIT;
        $query = $this->query($filters)->orderByDesc('audit_log.id')->limit($limit + 1);
        if (isset($filters['cursor'])) {
            $query->where('audit_log.id', '<', (int) $filters['cursor']);
        }
        $rows = array_values($query->get()->all());
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return [$rows, $more && $last instanceof AuditLog ? (string) $last->id : null];
    }

    private static function utc(CarbonImmutable $at): string
    {
        return $at->setTimezone('UTC')->format('Y-m-d H:i:s');
    }
}

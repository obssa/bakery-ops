<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The query-log assertion of the dashboards (phase-4 plan section 6, 4.4c; 10.9 item 19; ADR 0014), shared by
 * DashboardsReadSummariesOnlyTest (module:analytics, rows written straight into the summaries) and
 * DashboardsReadSummariesOnlyGateTest (gate:p4, the full seed). Every statement of a GET /api/v1/analytics/* request is recorded with
 * the tables it names (FROM, JOIN, INTO, UPDATE, subqueries included) and the phase it ran in:
 *   - the request's plumbing, before the endpoint resolves its RangeRequest: auth:sanctum, the actor middleware (the branch, the device,
 *     the acting-as session, the station the request works at and the shift running there) and the permission check. It may name only
 *     the tables of PLUMBING (the station's shift stamp reads shift_instances there, for every route of the API, not for a dashboard);
 *   - the endpoint: it may name only the summaries (daily_* and analytics_days), the name tables behind the Catalog, Payments and Identity
 *     contracts (items with their units and categories, payment_channels, users, stations, and user_station_assignments for the caller's
 *     own stations) and the framework's own (personal_access_tokens, the permission tables, idempotency_keys, cache).
 * orders, order_lines, payments, payment_refunds, stock_movements, stock_balances, production_*, shift_*, stock_counts* and audit_log are
 * never allowed to an endpoint, except the one INSERT INTO audit_log an export makes (its own audit row, step 4.4d).
 */
final class DashboardQueries
{
    public const array SUMMARIES = ['analytics_days', 'daily_sales', 'daily_hour_sales', 'daily_channel_totals', 'daily_item_metrics', 'daily_station_metrics', 'daily_user_metrics'];

    public const array NAMES = ['items', 'units', 'categories', 'payment_channels', 'users', 'stations', 'user_station_assignments'];

    public const array FRAMEWORK = ['personal_access_tokens', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'idempotency_keys', 'cache', 'cache_locks'];

    public const array PLUMBING = ['personal_access_tokens', 'users', 'branches', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        'cache', 'cache_locks', 'devices', 'acting_sessions', 'user_station_assignments', 'stations', 'shift_instances'];

    public const string FORBIDDEN = '/^(orders|order_lines|payments|payment_refunds|stock_movements|stock_balances|production_.*|shift_.*|stock_counts.*|audit_log)$/';

    /** The one statement on audit_log an export may make: its own audit row. */
    public const string EXPORT_AUDIT = '/^\s*insert\s+into\s+[`"]?audit_log[`"]?\s/i';

    /** The tables a statement names. @return list<string> */
    public static function tables(string $sql): array
    {
        preg_match_all('/\b(?:from|join|into|update)\s+[`"]?([a-z_][a-z0-9_]*)[`"]?/i', $sql, $m);

        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    /**
     * What the recorded statements touched that they may not.
     *
     * @param  list<array{sql: string, phase: string, export?: bool}>  $statements
     * @return list<string>
     */
    public static function violations(array $statements): array
    {
        $violations = [];
        foreach ($statements as $statement) {
            $allowed = $statement['phase'] === 'endpoint' ? array_merge(self::SUMMARIES, self::NAMES, self::FRAMEWORK) : self::PLUMBING;
            if (($statement['export'] ?? false) && $statement['phase'] === 'endpoint' && preg_match(self::EXPORT_AUDIT, $statement['sql']) === 1) {
                continue;
            }
            foreach (self::tables($statement['sql']) as $table) {
                $forbidden = $statement['phase'] === 'endpoint' && preg_match(self::FORBIDDEN, $table) === 1;
                if ($forbidden || ! in_array($table, $allowed, true)) {
                    $violations[] = "{$statement['phase']}: {$table} in ".$statement['sql'];
                }
            }
        }

        return $violations;
    }
}

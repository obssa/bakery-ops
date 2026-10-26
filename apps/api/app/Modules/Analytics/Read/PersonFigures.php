<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Read;

use App\Modules\Analytics\Http\Requests\StaffRequest;
use stdClass;

/**
 * A person's figures in one kind of work as GET /analytics/staff gives them (step 4.4c) and the staff export prints them (step 4.4d), from
 * daily_user_metrics summed over a period or from one day's row. role_kind is what the person did (waiter, cashier, station), not the role
 * they hold (ADR 0014); avg_prep_seconds is exact over the days (prep_seconds_sum over prep_samples, half-up). Pure.
 */
final class PersonFigures
{
    /**
     * @param  array<string, mixed>  $person  the person's name (Names::people)
     * @return array<string, mixed>
     */
    public static function of(array $person, stdClass $row): array
    {
        $samples = Sum::int($row->prep_samples);

        return [
            'user' => $person,
            'role_kind' => (string) $row->role_kind,
            'orders_count' => Sum::int($row->orders_count),
            'items_qty' => Sum::qty($row->items_qty),
            'revenue' => Sum::money($row->revenue),
            'payments_count' => Sum::int($row->payments_count),
            'payments_amount' => Sum::money($row->payments_amount),
            'voids_count' => Sum::int($row->voids_count),
            'refunds_count' => Sum::int($row->refunds_count),
            'lines_prepared' => Sum::int($row->lines_prepared),
            'prep_samples' => $samples,
            'avg_prep_seconds' => Sum::meanSeconds(Sum::int($row->prep_seconds_sum), $samples),
        ];
    }

    /**
     * The rows by kind of work (waiter, cashier, station), then by person.
     *
     * @param  list<stdClass>  $rows
     * @return list<stdClass>
     */
    public static function sorted(array $rows): array
    {
        usort($rows, static fn (stdClass $a, stdClass $b): int => array_search($a->role_kind, StaffRequest::ROLE_KINDS, true) <=> array_search($b->role_kind, StaffRequest::ROLE_KINDS, true)
            ?: (int) $a->user_id <=> (int) $b->user_id);

        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Http\Requests\StaffRequest;
use App\Modules\Analytics\Read\Dashboard;
use App\Modules\Analytics\Read\Names;
use App\Modules\Analytics\Read\PersonFigures;
use App\Modules\Analytics\Read\Summaries;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Contracts\Auth\Access\Authorizable;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /analytics/staff?role_kind=waiter|cashier|station (phase-4 plan section 4.6, step 4.4c), from daily_user_metrics, people named
 * through Identity's ActorDirectory (someone who has left too). analytics.view (of the signed-in account) reads everyone; analytics.view_own
 * only the rows of the person at the device (the acting-as person of a shared pad, else the signed-in one), who is part of the ETag.
 *   data: {users: [{user {id, name}, role_kind, orders_count, items_qty, revenue, payments_count, payments_amount, voids_count, refunds_count,
 *          lines_prepared, prep_samples, avg_prep_seconds}]} by role kind (waiter, cashier, station), then by person.
 * role_kind is what the person did, not the role they hold (ADR 0014); avg_prep_seconds is exact over the range (prep_seconds_sum over
 * prep_samples, half-up). Three queries.
 */
final class StaffController
{
    public function index(StaffRequest $request, CurrentBranch $branch, ActorContext $actor, Dashboard $dashboard, Summaries $summaries, Names $names): Response
    {
        $window = $request->window($branch->timezone());
        $roleKind = $request->roleKind();
        $user = $request->user();
        $own = $user instanceof Authorizable && $user->can('analytics.view') ? null : ($actor->effectiveUserId() ?? $actor->requireUserId());

        return $dashboard->respond($request, 'staff', $window, ['user' => $own, 'role_kind' => $roleKind], function () use ($window, $own, $roleKind, $summaries, $names): array {
            $rows = $summaries->staff($window, $own, $roleKind);
            $ids = [];
            foreach ($rows as $period) {
                $ids = array_merge($ids, array_map(static fn (stdClass $row): int => (int) $row->user_id, $period));
            }
            $named = $names->people(array_values(array_unique($ids)));

            $data = [];
            foreach (array_keys($window->periods()) as $p) {
                $people = PersonFigures::sorted($rows[$p] ?? []);
                $data[$p] = ['users' => array_map(static fn (stdClass $row): array => PersonFigures::of(Names::of($named, (int) $row->user_id), $row), $people)];
            }

            return $data;
        });
    }
}

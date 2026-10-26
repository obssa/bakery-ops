<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Read;

use App\Shared\Live\ConditionalPoll;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Time\LocalDay;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The answer every dashboard read gives (phase-4 plan section 4.6, step 4.4c):
 *   {from, to, timezone, as_of, stale_days, data, previous?: {from, to, as_of, stale_days, data}}
 * as_of and stale_days are each period's own (BuiltDays::freshness); previous is there only with ?compare=previous. Conditional
 * (ConditionalPoll, as GET /analytics/freshness): the ETag is the read's scope, the branch, today's local date (is_new turns at local
 * midnight), the periods, what else the answer depends on ($parts: the query's options, the caller's own stations or id) and every built
 * day's version and rebuilt_at in the span read. A version is written in the rebuild's own transaction and seen whole or not at all, so the
 * tag needs no settling bucket. One query (analytics_days) before a 304; the figures are read only for a 200. A name changed since the
 * last rebuild of the range is seen with the next one (a rebuild of today follows every payment).
 */
final class Dashboard
{
    public function __construct(private readonly CurrentBranch $branch) {}

    /**
     * @param  array<string, int|string|bool|list<int>|null>  $parts
     * @param  Closure(BuiltDays): array<int, array<string, mixed>>  $figures  the data of each period of the window (0 the range, 1 the period before)
     * @param  LocalDay|null  $also  more days the answer reads beyond the window (the weeks since a product's launch)
     */
    public function respond(Request $request, string $scope, Window $window, array $parts, Closure $figures, ?LocalDay $also = null): Response
    {
        $branchId = $this->branch->id();
        $zone = $this->branch->timezone();
        $from = $also !== null && $also->from < $window->first() ? $also->from : $window->first();
        $to = $also !== null && $also->to > $window->last() ? $also->to : $window->last();
        $built = BuiltDays::read($branchId, $from, $to);

        $etag = ConditionalPoll::etag('analytics.'.$scope, [
            'branch' => $branchId,
            'today' => CarbonImmutable::now($zone)->toDateString(),
            'range' => $window->current->from.'/'.$window->current->to,
            'previous' => $window->previous === null ? null : $window->previous->from.'/'.$window->previous->to,
            'parts' => json_encode($parts, JSON_THROW_ON_ERROR),
            'days' => $built->versions(),
        ]);

        return ConditionalPoll::respond($request, $etag, function () use ($window, $zone, $built, $figures): array {
            $data = $figures($built);
            $answer = ['from' => $window->current->from, 'to' => $window->current->to, 'timezone' => $zone]
                + $built->freshness($window->current)
                + ['data' => $data[0]];
            if ($window->previous !== null) {
                $answer['previous'] = ['from' => $window->previous->from, 'to' => $window->previous->to]
                    + $built->freshness($window->previous)
                    + ['data' => $data[1] ?? []];
            }

            return $answer;
        });
    }
}

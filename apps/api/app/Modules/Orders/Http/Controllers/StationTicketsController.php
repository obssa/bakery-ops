<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Contracts\Tickets;
use App\Modules\Orders\Support\OrderAccess;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Models\Station;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /stations/{station}/tickets?since=<cursor> (orders.accept at that station, or stations.all; phase-1 plan
 * section 4, ADR 0009; step 1.5): the rail of one station, the HTTP face of Tickets::since. The ETag comes from
 * Tickets::fingerprint (a few indexed queries), so a matching If-None-Match is answered 304 without building the page
 * of tickets; otherwise the body is exactly TicketsPage::toArray. Another branch's station is 404 (route binding goes
 * through the BranchScoped model); a station the person does not work at is 403 orders.station.forbidden.
 */
final class StationTicketsController
{
    public function __construct(private readonly OrderAccess $access, private readonly Tickets $tickets) {}

    public function __invoke(Request $request, Station $station): Response
    {
        $this->access->assertAtStationRail($station);
        $cursor = $request->query('since');
        $cursor = is_string($cursor) ? $cursor : null;

        $etag = Tickets::etag($station->id, $this->tickets->fingerprint($station->id, $cursor));

        return ConditionalPoll::respond($request, $etag, fn (): array => $this->tickets->since($station->id, $cursor)->toArray());
    }
}

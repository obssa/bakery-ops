<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Http\Requests\MenuRequest;
use App\Modules\Orders\Support\MenuBuilder;
use App\Shared\Live\ConditionalPoll;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /menu (orders.create; phase-1 plan section 4, step 1.5): what the pad can sell now (MenuBuilder). Polled by every
 * pad, so it answers 304 from a handful of aggregate queries when nothing the menu shows has moved (ConditionalPoll,
 * ADR 0009) and builds the body only otherwise. ?location=<id> shows every product at one location,
 * ?include_unavailable=1 lists the sold-out ones too (available 0, unavailable_reason).
 */
final class MenuController
{
    public function __invoke(MenuRequest $request, MenuBuilder $menu): Response
    {
        $location = $request->locationId();
        $all = $request->includeUnavailable();
        $now = CarbonImmutable::now();

        return ConditionalPoll::respond($request, $menu->etag($location, $all, $now), fn (): array => $menu->build($location, $all, $now));
    }
}

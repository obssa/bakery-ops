<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\Audience;
use App\Modules\Notifications\Support\EventScope;
use App\Modules\Notifications\Support\NotificationTypes;
use App\Shared\Events\StockOut;
use App\Shared\Models\Station;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * StockOut -> the people who can do something about it (plan section 5, the defaults of config/notifications.php):
 *  - a PRODUCT that ran out: the people on duty at the station that makes it (the event names it; else the station of the
 *    place), everybody who holds production.confirm, and the roles the registry names (admin, manager);
 *  - an INGREDIENT that ran out: the roles the registry names and the people assigned to the station of the place (the chef
 *    must hear that the kitchen's flour is gone).
 * The in-app line goes to all of them. Push and Telegram do not go to the station-only people of an ingredient (plan section
 * 5: "in-app only for the latter"): they see it in the bell, the roles are reached wherever they are (Notifier $inAppOnly).
 *
 * Queued after commit on the default queue; the delivery gate (Support\Dedupe, per item and place, cleared by StockRecovered)
 * makes it fire once per run-out however often the event is delivered. Runs as the person whose movement caused the
 * stock-out, in the event's branch (Support\EventScope).
 */
final class NotifyStockOut implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public int $tries = 3;

    public function __construct(
        private readonly EventScope $scope,
        private readonly Recipients $recipients,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(StockOut $event): void
    {
        $this->scope->run($event->branchId, $event->causedByUserId, function () use ($event): void {
            [$audience, $inAppOnly] = $this->audience($event);
            $this->notifier->send($audience, 'stock_out', $event->payload(), null, $inAppOnly);
        });
    }

    /**
     * The people to tell, and the ones among them who are told in the inbox only (not by push or Telegram): the people of the
     * station of the place when an INGREDIENT ran out, unless a role or the like already puts them in the first group.
     *
     * @return array{0: Collection<int, covariant Model>, 1: list<int>}
     */
    private function audience(StockOut $event): array
    {
        $roles = $this->recipients->byRoles(NotificationTypes::notifyRoles('stock_out'));
        if ($event->itemKind === 'product') {
            $station = $this->station($event->productionStationId ?? $event->stationId);

            return [Audience::of(
                $station === null ? new Collection : $this->recipients->onDuty($station),
                $this->recipients->byPermission('production.confirm'),
                $roles,
            ), []];
        }
        $station = $this->station($event->stationId);
        $atStation = $station === null ? new Collection : $this->recipients->forStation($station);
        $inAppOnly = array_values(array_diff(
            $atStation->map(static fn (Model $person): int => (int) $person->getKey())->all(),
            $roles->map(static fn (Model $person): int => (int) $person->getKey())->all(),
        ));

        return [Audience::of($roles, $atStation), $inAppOnly];
    }

    private function station(?int $id): ?Station
    {
        return $id === null ? null : Station::query()->find($id);
    }
}

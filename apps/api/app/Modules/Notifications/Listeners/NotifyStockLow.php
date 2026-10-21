<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Support\Audience;
use App\Modules\Notifications\Support\EventScope;
use App\Shared\Events\StockLow;
use App\Shared\Models\Station;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * StockLow -> the people who can do something about it (plan section 5, ADR 0011). The reorder rule names the roles that hear
 * of it (notify_roles, a list from the permission registry plus the literal "station"); on top of that:
 *  - a PRODUCT that is running low: the people on duty at the station that makes it (the event names it; else the station of
 *    the place) and everybody who holds production.confirm, as for a stock-out;
 *  - an INGREDIENT: the people assigned to the station of the place, when the rule lists "station". Push and Telegram do
 *    not go to the station-only people (plan section 5: "in-app only for the latter"): they see it in the bell, the roles are
 *    reached wherever they are (Notifier $inAppOnly).
 * A rule that lists only some roles tells only those (plus the station people above); the registry default is admin and manager.
 *
 * Queued after commit on the default queue; the delivery gate (Support\Dedupe, per item and place, cleared by StockRecovered
 * of type low) makes it fire once per episode however often the event is delivered, and a stock-out never uses it up. Runs as
 * the person whose movement started the episode, in the event's branch (Support\EventScope).
 */
final class NotifyStockLow implements ShouldHandleEventsAfterCommit, ShouldQueue
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

    public function handle(StockLow $event): void
    {
        $this->scope->run($event->branchId, $event->causedByUserId, function () use ($event): void {
            [$audience, $inAppOnly] = $this->audience($event);
            $this->notifier->send($audience, 'stock_low', $event->payload(), null, $inAppOnly);
        });
    }

    /**
     * The people to tell, and the ones among them who are told in the inbox only (not by push or Telegram).
     *
     * @return array{0: Collection<int, covariant Model>, 1: list<int>}
     */
    private function audience(StockLow $event): array
    {
        $roles = array_values(array_diff($event->notifyRoles, ['station']));
        $byRole = $roles === [] ? new Collection : $this->recipients->byRoles($roles);
        if ($event->itemKind === 'product') {
            $station = $this->station($event->productionStationId ?? $event->stationId);

            return [Audience::of(
                $station === null ? new Collection : $this->recipients->onDuty($station),
                $this->recipients->byPermission('production.confirm'),
                $byRole,
            ), []];
        }
        $station = in_array('station', $event->notifyRoles, true) ? $this->station($event->stationId) : null;
        $atStation = $station === null ? new Collection : $this->recipients->forStation($station);
        $inAppOnly = array_values(array_diff(
            $atStation->map(static fn (Model $person): int => (int) $person->getKey())->all(),
            $byRole->map(static fn (Model $person): int => (int) $person->getKey())->all(),
        ));

        return [Audience::of($byRole, $atStation), $inAppOnly];
    }

    private function station(?int $id): ?Station
    {
        return $id === null ? null : Station::query()->find($id);
    }
}

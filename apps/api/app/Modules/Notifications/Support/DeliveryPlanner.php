<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Notifications\Drivers\DriverRegistry;
use App\Modules\Notifications\Jobs\DeliverNotification;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Support\Facades\Log;

/**
 * Decides who is reached outside the app for the inbox lines Contracts\Notifier just wrote, and queues the sends (plan
 * section 5). For each line, a channel is used when ALL of these hold:
 *  - the type lists it (config/notifications.php: stock_out is in_app + push + telegram, line_ready in_app only, ...);
 *  - its driver is on (DriverRegistry): a channel that is off is skipped and writes no row (plan section 3, migration 11);
 *  - the person was not chosen as in-app only by the listener (the station-only people of an ingredient stock-out);
 *  - the person has a live endpoint for it (not revoked, not deleted); one delivery per endpoint.
 * Each delivery is written pending, then one DeliverNotification is queued for it (after commit, default queue). The inbox
 * line is already committed and never depends on any of this. Resolved per use: it carries the scoped CurrentBranch.
 */
final class DeliveryPlanner
{
    public function __construct(
        private readonly DriverRegistry $drivers,
        private readonly CurrentBranch $branch,
    ) {}

    /**
     * @param  list<Notification>  $lines  the lines just written
     * @param  list<int>  $inAppOnly  user ids who are told in the inbox only
     * @return list<NotificationDelivery> the deliveries planned, in queue order
     */
    public function plan(array $lines, array $inAppOnly = []): array
    {
        $branchId = $this->branch->id();
        $planned = [];
        foreach ($lines as $line) {
            if (in_array($line->user_id, $inAppOnly, true)) {
                continue;
            }
            foreach (NotificationTypes::channels($line->type) as $channel) {
                if ($channel === 'in_app') {
                    continue;
                }
                if ($this->drivers->for($channel) === null) {
                    Log::debug('notification channel skipped, its driver is off', ['channel' => $channel, 'type' => $line->type]);

                    continue;
                }
                $endpoints = NotificationEndpoint::query()
                    ->where('user_id', $line->user_id)->where('driver', $channel)->whereNull('revoked_at')
                    ->orderBy('id')->get();
                foreach ($endpoints as $endpoint) {
                    $delivery = new NotificationDelivery;
                    $delivery->forceFill([
                        'branch_id' => $branchId,
                        'notification_id' => $line->id,
                        'driver' => $channel,
                        'endpoint_id' => $endpoint->id,
                        'status' => 'pending',
                        'attempts' => 0,
                    ])->save();
                    $planned[] = $delivery;
                }
            }
        }

        foreach ($planned as $delivery) {
            DeliverNotification::dispatch($delivery->id, $branchId);
        }

        return $planned;
    }
}

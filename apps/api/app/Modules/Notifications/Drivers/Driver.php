<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Drivers;

use App\Modules\Notifications\Models\NotificationDelivery;

/**
 * A way to reach a person outside the app (manifest: notifications drivers behind one interface; plan sections 5 and 10.1).
 * name() is the channel it serves (in_app, push, telegram, sms) and enabled() whether it may send. send() delivers one
 * planned delivery to its endpoint, in the language of the recipient, and answers how it ended:
 *
 *  - DeliveryOutcome::Sent: the service accepted it;
 *  - DeliveryOutcome::Gone: the address no longer exists (a push subscription answered 404 or 410, a Telegram chat that
 *    blocked the bot), so the endpoint is revoked and the delivery is not tried again;
 *  - anything else is a thrown exception (DriverFailure or a transport error): the delivery keeps its error and is tried again.
 * The inbox line is the in_app delivery and is never sent through send().
 */
interface Driver
{
    public function name(): string;

    public function enabled(): bool;

    public function send(NotificationDelivery $delivery): DeliveryOutcome;
}

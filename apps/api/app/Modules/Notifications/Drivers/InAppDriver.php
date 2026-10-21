<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Drivers;

use App\Modules\Notifications\Models\NotificationDelivery;

/**
 * The inbox itself (plan section 10.1): the line Contracts\Notifier writes IS the in_app delivery, so this driver is always on,
 * has no setting that could switch it off by mistake, and has nothing to send. It exists so every channel, in_app included,
 * answers the same question through the registry.
 */
final class InAppDriver implements Driver
{
    public function name(): string
    {
        return 'in_app';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function send(NotificationDelivery $delivery): DeliveryOutcome
    {
        return DeliveryOutcome::Sent;
    }
}

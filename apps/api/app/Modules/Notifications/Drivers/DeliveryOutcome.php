<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Drivers;

/** How a driver ended one delivery without failing (a failure is an exception, see Driver). */
enum DeliveryOutcome
{
    /** The service accepted the message. */
    case Sent;

    /** The address no longer exists: revoke the endpoint, do not try again. */
    case Gone;
}

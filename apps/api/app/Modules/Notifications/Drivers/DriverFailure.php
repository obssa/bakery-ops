<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Drivers;

use RuntimeException;

/**
 * A send that did not go through and may work later (a server error, a rate limit, a timeout). The message is what the
 * delivery row keeps as last_error, so a driver writes it from the status and the service's own reason and scrubs every key and
 * token out of it first (Drivers\Scrub).
 */
final class DriverFailure extends RuntimeException {}

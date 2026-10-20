<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use RuntimeException;

/**
 * Internal to SendOrder: once the order row was locked it turned out no longer open (a concurrent send won, or a
 * cancel). Thrown to roll the send's transaction back (no audit row for a send that did nothing); SendOrder catches it
 * and answers the order as it now is. Never reaches the client.
 */
final class SentMeanwhile extends RuntimeException {}

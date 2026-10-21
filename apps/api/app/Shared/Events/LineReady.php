<?php

declare(strict_types=1);

namespace App\Shared\Events;

/**
 * A station line is made (-> ready; its reservation was consumed in the same transaction, ADR 0008): the owner's pad
 * shows the ready badge and Notifications puts a line in the owner's inbox (plan section 5). Names the order and the item
 * (NamedLineEvent).
 */
final class LineReady extends NamedLineEvent {}

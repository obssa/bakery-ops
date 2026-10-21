<?php

declare(strict_types=1);

namespace App\Shared\Events;

/**
 * An item has none left to offer at a place: available (on_hand - reserved) fell to 0 or below (manifest
 * inventory.events; phase-2 plan section 5; ADR 0011). Dispatched by Inventory's balance evaluation exactly once per
 * episode: when the stock_alert_states row is opened, never again until StockRecovered has closed it. Production's
 * auto-request and Notifications subscribe to it without importing Inventory.
 */
final class StockOut extends StockAlert {}

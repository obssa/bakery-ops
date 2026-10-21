<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Events\LineReady;
use App\Shared\Events\LineRejected;
use App\Shared\Events\ProductionAssigned;
use App\Shared\Events\ProductionRequested;
use App\Shared\Events\StockLow;
use App\Shared\Events\StockOut;
use App\Shared\Events\StockRecovered;
use App\Shared\Events\TransferDecided;
use App\Shared\Events\TransferRequested;

/**
 * The events Notifications listens to, built the way their producers build them (the payload shapes are the contract:
 * Notifications writes its text from the payload alone). Each takes the ids a test cares about and overrides for the rest.
 */
final class NotificationEvents
{
    /**
     * @param  array<string, mixed>  $over
     */
    public static function lineReady(int $branchId, int $ownerId, int $lineId = 31, array $over = []): LineReady
    {
        $a = $over + ['orderId' => 21, 'orderNo' => 12, 'tableCode' => 'T4', 'stationId' => null, 'byUserId' => $ownerId, 'nameEn' => 'Chicken sandwich', 'nameAm' => 'የዶሮ ሳንድዊች'];

        return new LineReady($branchId, $lineId, $a['orderId'], $a['stationId'], $a['byUserId'], $ownerId, $a['orderNo'], $a['tableCode'], $a['nameEn'], $a['nameAm']);
    }

    /**
     * @param  array<string, mixed>  $over
     */
    public static function lineRejected(int $branchId, int $ownerId, int $lineId = 32, array $over = []): LineRejected
    {
        $a = $over + [
            'orderId' => 21, 'orderNo' => 12, 'tableCode' => 'T4', 'stationId' => null, 'byUserId' => $ownerId, 'nameEn' => 'Chicken sandwich', 'nameAm' => 'የዶሮ ሳንድዊች',
            'reason' => 'out_of_stock', 'reasonEn' => 'Out of stock.', 'reasonAm' => 'ክምችቱ አልቋል።', 'note' => null,
        ];

        return new LineRejected(
            $branchId, $lineId, $a['orderId'], $a['stationId'], $a['byUserId'], $ownerId, $a['orderNo'], $a['tableCode'], $a['nameEn'], $a['nameAm'],
            $a['reason'], $a['reasonEn'], $a['reasonAm'], $a['note'],
        );
    }

    /**
     * @param  array<string, mixed>  $over
     */
    public static function stockOut(int $branchId, int $itemId = 11, int $locationId = 4, int $alertId = 21, array $over = []): StockOut
    {
        $a = $over + [
            'kind' => 'product', 'fulfilment' => 'to_stock', 'causedBy' => null, 'stationId' => null, 'productionStationId' => null,
            'itemEn' => 'Black forest cake', 'itemAm' => 'ብላክ ፎረስት ኬክ', 'placeEn' => 'Display case', 'placeAm' => 'የማሳያ ሣጥን',
        ];

        return new StockOut(
            branchId: $branchId, itemId: $itemId, locationId: $locationId, itemNameEn: $a['itemEn'], itemNameAm: $a['itemAm'],
            locationNameEn: $a['placeEn'], locationNameAm: $a['placeAm'], itemKind: $a['kind'], fulfilment: $a['fulfilment'],
            available: '0.0000', onHand: '0.0000', reserved: '0.0000', alertId: $alertId, causedByUserId: $a['causedBy'],
            stationId: $a['stationId'], productionStationId: $a['productionStationId'],
        );
    }

    /**
     * @param  array<string, mixed>  $over
     */
    public static function stockLow(int $branchId, int $itemId = 12, int $locationId = 1, int $alertId = 31, array $over = []): StockLow
    {
        $a = $over + [
            'kind' => 'ingredient', 'fulfilment' => 'bought_in', 'causedBy' => null, 'stationId' => null, 'productionStationId' => null,
            'itemEn' => 'Flour', 'itemAm' => 'ዱቄት', 'placeEn' => 'Store', 'placeAm' => 'መጋዘን',
            'available' => '4000.0000', 'minQty' => '5000.0000', 'reorderQty' => '25000.0000', 'roles' => ['admin', 'manager'], 'unit' => 'g',
        ];

        return new StockLow(
            branchId: $branchId, itemId: $itemId, locationId: $locationId, itemNameEn: $a['itemEn'], itemNameAm: $a['itemAm'],
            locationNameEn: $a['placeEn'], locationNameAm: $a['placeAm'], itemKind: $a['kind'], fulfilment: $a['fulfilment'],
            available: $a['available'], onHand: $a['available'], reserved: '0.0000', alertId: $alertId, causedByUserId: $a['causedBy'],
            stationId: $a['stationId'], productionStationId: $a['productionStationId'],
            minQty: $a['minQty'], reorderQty: $a['reorderQty'], notifyRoles: $a['roles'], unit: $a['unit'],
        );
    }

    public static function stockRecovered(int $branchId, int $itemId = 11, int $locationId = 4, string $type = 'out', int $alertId = 21): StockRecovered
    {
        return new StockRecovered($branchId, $itemId, $locationId, $type, $alertId);
    }

    /**
     * @param  array<string, mixed>  $over
     */
    public static function transferRequested(int $branchId, int $requesterId, int $transferId = 41, array $over = []): TransferRequested
    {
        $a = $over + ['fromStationId' => null, 'toStationId' => null, 'qty' => '5.0000', 'unit' => 'kg'];

        return new TransferRequested(
            $branchId, $transferId, 12, 'Flour', 'ዱቄት', 1, 'Store', 'መጋዘን', $a['fromStationId'],
            2, 'Kitchen', 'ወጥ ቤት', $a['toStationId'], $a['qty'], $a['unit'], $requesterId, null,
        );
    }

    /**
     * @param  array<string, mixed>  $over
     */
    public static function transferDecided(int $branchId, int $requesterId, int $deciderId, string $status = 'confirmed', int $transferId = 41, array $over = []): TransferDecided
    {
        $a = $over + ['fromStationId' => null, 'toStationId' => null, 'qty' => '5.0000', 'unit' => 'kg', 'reason' => $status === 'rejected' ? 'The sack is for the wedding cake' : null];

        return new TransferDecided(
            $branchId, $transferId, 12, 'Flour', 'ዱቄት', 1, 'Store', 'መጋዘን', $a['fromStationId'],
            2, 'Kitchen', 'ወጥ ቤት', $a['toStationId'], $a['qty'], $a['unit'], $requesterId, $status, $a['reason'], $deciderId,
        );
    }

    /**
     * @param  array<string, mixed>  $over
     */
    public static function productionRequested(int $branchId, int $requesterId, int $requestId = 51, array $over = []): ProductionRequested
    {
        $a = $over + ['stationId' => 1, 'reason' => 'manual', 'priority' => 'normal', 'dueAt' => null, 'qty' => '12.0000', 'unit' => 'pcs'];

        return new ProductionRequested(
            $branchId, $requestId, 17, 'Black forest cake', 'ብላክ ፎረስት ኬክ', $a['qty'], $a['unit'],
            $a['stationId'], 'Cake room', 'የኬክ ክፍል', $a['reason'], $a['priority'], $a['dueAt'], $requesterId,
        );
    }

    /**
     * @param  array<string, mixed>  $over
     */
    public static function productionAssigned(int $branchId, int $assigneeId, int $assignerId, int $requestId = 51, array $over = []): ProductionAssigned
    {
        $a = $over + ['stationId' => 1, 'qty' => '12.0000', 'unit' => 'pcs', 'version' => 2];

        return new ProductionAssigned(
            $branchId, $requestId, 17, 'Black forest cake', 'ብላክ ፎረስት ኬክ', $a['qty'], $a['unit'],
            $a['stationId'], 'Cake room', 'የኬክ ክፍል', $assigneeId, $assignerId, $a['version'],
        );
    }
}

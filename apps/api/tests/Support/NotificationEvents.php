<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Events\BatchDone;
use App\Shared\Events\ChainBroken;
use App\Shared\Events\CountSubmitted;
use App\Shared\Events\LineReady;
use App\Shared\Events\LineRejected;
use App\Shared\Events\PaymentDisputed;
use App\Shared\Events\ProductionAssigned;
use App\Shared\Events\ProductionRequested;
use App\Shared\Events\RefundPaid;
use App\Shared\Events\StockLow;
use App\Shared\Events\StockOut;
use App\Shared\Events\StockRecovered;
use App\Shared\Events\TransferDecided;
use App\Shared\Events\TransferRequested;
use App\Shared\Events\VarianceDecided;
use App\Shared\Events\WeeklySummaryReady;

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

    /**
     * @param  array<string, mixed>  $over
     */
    public static function batchDone(int $branchId, string $status, int $finishedBy, ?int $confirmedBy, int $requestedBy, ?int $assignedTo, int $batchId = 71, array $over = []): BatchDone
    {
        $a = $over + ['requestId' => 51, 'produced' => '10.0000', 'waste' => '2.0000', 'unit' => 'pcs', 'stationId' => 1, 'sendBackCount' => 0];

        return new BatchDone(
            $branchId, $batchId, $a['requestId'], $status, 17, 'Black forest cake', 'ብላክ ፎረስት ኬክ', $a['produced'], $a['waste'], $a['unit'],
            $a['stationId'], 'Cake room', 'የኬክ ክፍል', $finishedBy, $confirmedBy, $requestedBy, $assignedTo, $a['sendBackCount'],
        );
    }

    /**
     * A closing count at the kitchen with one line 500 g short (variance_lines 1), handed in by $countedBy.
     *
     * @param  array<string, mixed>  $over
     */
    public static function countSubmitted(int $branchId, int $countedBy, string $statusAfter = 'pending_approval', int $countId = 61, array $over = []): CountSubmitted
    {
        $a = $over + [
            'shiftId' => 7, 'stationId' => 1, 'stationCode' => 'kitchen', 'stationEn' => 'Kitchen', 'stationAm' => 'ወጥ ቤት', 'locationId' => 3,
            'locationEn' => 'Kitchen shelf', 'locationAm' => 'የወጥ ቤት መደርደሪያ', 'type' => 'closing', 'seq' => 1, 'lines' => 4, 'varianceLines' => 1,
            'items' => [['item_name_en' => 'Flour', 'item_name_am' => 'ዱቄት', 'expected_qty' => '10000.0000', 'counted_qty' => '9500.0000', 'variance_qty' => '-500.0000', 'unit' => 'g']],
            'cash' => null, 'cosignedBy' => null,
        ];

        return new CountSubmitted(
            $branchId, $countId, $a['shiftId'], $a['stationId'], $a['stationCode'], $a['stationEn'], $a['stationAm'], $a['locationId'], $a['locationEn'], $a['locationAm'],
            $a['type'], $a['seq'], $statusAfter, $a['lines'], $a['varianceLines'], $a['items'], $a['cash'], $countedBy, $a['cosignedBy'],
        );
    }

    /**
     * @param  array<string, mixed>  $over
     */
    public static function varianceDecided(int $branchId, int $countedBy, int $decidedBy, string $decision = 'approved', int $countId = 61, array $over = []): VarianceDecided
    {
        $a = $over + [
            'shiftId' => 7, 'stationId' => 1, 'stationCode' => 'kitchen', 'stationEn' => 'Kitchen', 'stationAm' => 'ወጥ ቤት', 'locationId' => 3,
            'locationEn' => 'Kitchen shelf', 'locationAm' => 'የወጥ ቤት መደርደሪያ', 'type' => 'closing',
            'note' => $decision === 'rejected' ? 'Count the top shelf again' : null,
            'adjusted' => $decision === 'rejected' ? 0 : 2, 'recount' => $decision === 'rejected' ? 62 : null,
        ];

        return new VarianceDecided(
            $branchId, $countId, $a['shiftId'], $a['stationId'], $a['stationCode'], $a['stationEn'], $a['stationAm'], $a['locationId'], $a['locationEn'], $a['locationAm'],
            $a['type'], $decision, $decidedBy, $a['note'], $countedBy, $a['adjusted'], $a['recount'],
        );
    }

    /**
     * The chain of the kitchen shelf failed at link $seq (the person $causedBy made that link).
     *
     * @param  array<string, mixed>  $over
     */
    public static function chainBroken(int $branchId, ?int $causedBy = 6, int $seq = 2, string $kind = 'hash_mismatch', int $countId = 61, array $over = []): ChainBroken
    {
        $a = $over + ['locationId' => 3, 'locationEn' => 'Kitchen shelf', 'locationAm' => 'የወጥ ቤት መደርደሪያ'];

        return new ChainBroken($branchId, $a['locationId'], $a['locationEn'], $a['locationAm'], $seq, $kind, $countId, $causedBy);
    }

    /**
     * Step 4.3c: a payment of order 17 disputed by $byUserId (Payments' DisputePayment builds it the same way).
     *
     * @param  array<string, mixed>  $over
     */
    public static function paymentDisputed(int $branchId, int $byUserId, int $paymentId = 71, array $over = []): PaymentDisputed
    {
        $a = $over + [
            'orderId' => 21, 'orderNo' => 17, 'channelCode' => 'telebirr', 'channelEn' => 'Telebirr', 'channelAm' => 'ቴሌብር', 'amount' => '25.00',
            'reason' => 'Not on the bank statement',
        ];

        return new PaymentDisputed($branchId, $paymentId, $a['orderId'], $a['orderNo'], $a['channelCode'], $a['channelEn'], $a['channelAm'], $a['amount'], $a['reason'], $byUserId);
    }

    /**
     * Step 4.3d: refund 4 of order #17 (id 21) paid back, 120.00 in all: 50.00 in cash and 70.00 by Telebirr, asked by $byUserId and approved by
     * $approverId (Meron) unless $over says otherwise (refundId, orderId, orderNo, amount, isFull, reason, approverName, payments).
     *
     * @param  array<string, mixed>  $over
     */
    public static function refundPaid(int $branchId, int $byUserId, int $approverId, array $over = []): RefundPaid
    {
        $a = $over + [
            'refundId' => 4, 'orderId' => 21, 'orderNo' => 17, 'amount' => '120.00', 'isFull' => true, 'reason' => 'The cake was the wrong one',
            'approverName' => 'Meron',
            'payments' => [['payment_id' => 31, 'channel_code' => 'cash', 'amount' => '50.00'], ['payment_id' => 30, 'channel_code' => 'telebirr', 'amount' => '70.00']],
        ];

        return new RefundPaid($branchId, $a['refundId'], $a['orderId'], $a['orderNo'], $a['amount'], $a['isFull'], $a['reason'], $byUserId, $approverId, $a['approverName'], $a['payments']);
    }

    /**
     * Step 4.4d: the summary of the week 2026-11-16 to 2026-11-22 as Analytics' WeeklySummary builds it (the figures of WeeklySummaryTest),
     * announced by the Monday job unless $over says otherwise (weekStart, weekEnd, run, byUserId, and summary: figures to replace).
     *
     * @param  array<string, mixed>  $over
     */
    public static function weeklySummaryReady(int $branchId, array $over = []): WeeklySummaryReady
    {
        $summary = ($over['summary'] ?? []) + [
            'week_start' => '2026-11-16', 'week_end' => '2026-11-22', 'previous_start' => '2026-11-09', 'previous_end' => '2026-11-15', 'timezone' => 'Africa/Addis_Ababa',
            'as_of' => '2026-11-22T05:00:00Z', 'stale_days' => ['2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22'],
            'revenue' => '300.00', 'revenue_before' => '240.00', 'revenue_change_pct' => '25.00',
            'orders_count' => 4, 'orders_before' => 2, 'orders_change_pct' => '100.00',
            'net_revenue' => '280.00', 'avg_ticket' => '75.00',
            'refund_count' => 1, 'refund_total' => '20.00', 'voided_count' => 1, 'voided_total' => '30.00', 'cancelled_count' => 1,
            'best_items' => [
                ['item_id' => 11, 'name_en' => 'Croissant', 'name_am' => 'ክሮሳንት', 'units_sold_qty' => '3.0000', 'revenue' => '180.00', 'margin_total' => '134.50', 'margin_pct' => '74.72', 'cogs_complete' => true],
                ['item_id' => 12, 'name_en' => 'Bread', 'name_am' => 'ዳቦ', 'units_sold_qty' => '1.0000', 'revenue' => '30.25', 'margin_total' => '10.25', 'margin_pct' => '33.88', 'cogs_complete' => true],
            ],
            'worst_items' => [
                ['item_id' => 15, 'name_en' => 'Cake', 'name_am' => 'ኬክ', 'units_sold_qty' => '1.0000', 'revenue' => '59.75', 'margin_total' => '-5.00', 'margin_pct' => '-8.37', 'cogs_complete' => true],
            ],
            'channels' => [
                ['channel_id' => 1, 'code' => 'cash', 'name_en' => 'Cash', 'name_am' => 'ጥሬ ገንዘብ', 'amount' => '160.25', 'share' => '66.70'],
                ['channel_id' => 2, 'code' => 'telebirr', 'name_en' => 'Telebirr', 'name_am' => 'ቴሌብር', 'amount' => '80.00', 'share' => '33.30'],
            ],
            'variance_line_count' => 3, 'cash_variance_total' => '5.50',
            'stockout_count' => 4,
            'stockout_items' => [
                ['item_id' => 21, 'name_en' => 'Flour', 'name_am' => 'ዱቄት', 'stockout_count' => 2],
                ['item_id' => 11, 'name_en' => 'Croissant', 'name_am' => 'ክሮሳንት', 'stockout_count' => 1],
            ],
        ];

        return new WeeklySummaryReady(
            $branchId,
            $over['weekStart'] ?? '2026-11-16',
            $over['weekEnd'] ?? '2026-11-22',
            $over['run'] ?? 'scheduled',
            $summary,
            $over['byUserId'] ?? null,
        );
    }
}

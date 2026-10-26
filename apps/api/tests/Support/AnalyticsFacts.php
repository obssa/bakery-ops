<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Contracts\ItemSummary;
use App\Modules\Catalog\Contracts\RecipeLineView;
use App\Modules\Catalog\Contracts\RecipeView;
use App\Modules\Inventory\Contracts\ClosingBalanceFact;
use App\Modules\Inventory\Contracts\MovementFact;
use App\Modules\Inventory\Contracts\StockOutFact;
use App\Modules\Inventory\Contracts\UnitCostFact;
use App\Modules\Orders\Contracts\LineFact;
use App\Modules\Orders\Contracts\SaleFact;
use App\Modules\Orders\Contracts\StationLineFact;
use App\Modules\Orders\Contracts\VoidFact;
use App\Modules\Payments\Contracts\PaymentFact;
use App\Modules\Payments\Contracts\RefundFact;
use App\Modules\Production\Contracts\BatchFact;
use App\Modules\Shifts\Contracts\ShiftFact;
use App\Shared\Values\Quantity;

/**
 * The Facts rows of the providers built from plain arrays, for the unit tests of the Analytics builders (phase-4 plan section 7:
 * "one test per builder with array facts", no database). Every key has a dull default, so a test names only what it is about.
 * The day is 2026-11-20 (local, Africa/Addis_Ababa, UTC+3) unless a row says otherwise.
 */
final class AnalyticsFacts
{
    public const string DAY = '2026-11-20';

    public const string TZ = 'Africa/Addis_Ababa';

    /** @param  array<string, mixed>  $a */
    public static function sale(array $a = []): SaleFact
    {
        $total = (string) ($a['total'] ?? '100.00');

        return new SaleFact(
            (int) ($a['order_id'] ?? 1),
            (string) ($a['day'] ?? self::DAY),
            (int) ($a['hour'] ?? 9),
            (string) ($a['type'] ?? 'dine_in'),
            (string) ($a['status'] ?? 'closed'),
            (string) ($a['subtotal'] ?? $total),
            (string) ($a['discount'] ?? '0.00'),
            (string) ($a['tax'] ?? '0.00'),
            $total,
            (string) ($a['paid_total'] ?? $total),
            array_key_exists('owner_user_id', $a) ? $a['owner_user_id'] : 10,
            $a['shift_instance_id'] ?? null,
            $a['station_id'] ?? null,
            (string) ($a['settled_at'] ?? self::DAY.'T06:00:00Z'),
        );
    }

    /** @param  array<string, mixed>  $a */
    public static function line(array $a = []): LineFact
    {
        return new LineFact(
            (int) ($a['line_id'] ?? 1),
            (int) ($a['order_id'] ?? 1),
            (string) ($a['day'] ?? self::DAY),
            (int) ($a['item_id'] ?? 1),
            Quantity::of((string) ($a['qty'] ?? '1'))->toString(),
            (string) ($a['unit_price'] ?? '100.00'),
            (string) ($a['modifier_price'] ?? '0.00'),
            (string) ($a['line_total'] ?? '100.00'),
            $a['recipe_version'] ?? null,
            $a['station_id'] ?? null,
            $a['location_id'] ?? null,
            (string) ($a['status'] ?? 'served'),
            $a['accepted_at'] ?? null,
            $a['ready_at'] ?? null,
            $a['prepared_by_user_id'] ?? null,
        );
    }

    /** @param  array<string, mixed>  $a */
    public static function void(array $a = []): VoidFact
    {
        $status = (string) ($a['status'] ?? 'voided');

        return new VoidFact(
            (int) ($a['order_id'] ?? 1),
            (string) ($a['day'] ?? self::DAY),
            $status,
            (string) ($a['type'] ?? 'dine_in'),
            (string) ($a['total'] ?? '100.00'),
            array_key_exists('owner_user_id', $a) ? $a['owner_user_id'] : 10,
            array_key_exists('voided_by_user_id', $a) ? $a['voided_by_user_id'] : ($status === 'voided' ? 10 : null),
            (string) ($a['at'] ?? self::DAY.'T08:00:00Z'),
        );
    }

    /** @param  array<string, mixed>  $a */
    public static function stationLine(array $a = []): StationLineFact
    {
        return new StationLineFact(
            (int) ($a['line_id'] ?? 1),
            (int) ($a['order_id'] ?? 1),
            (string) ($a['day'] ?? self::DAY),
            (int) ($a['item_id'] ?? 1),
            (int) ($a['station_id'] ?? 1),
            Quantity::of((string) ($a['qty'] ?? '1'))->toString(),
            (string) ($a['status'] ?? 'ready'),
            $a['accepted_at'] ?? null,
            $a['ready_at'] ?? null,
            $a['served_at'] ?? null,
            $a['rejected_at'] ?? null,
            $a['prepared_by_user_id'] ?? null,
        );
    }

    /** @param  array<string, mixed>  $a */
    public static function payment(array $a = []): PaymentFact
    {
        return new PaymentFact(
            (int) ($a['payment_id'] ?? 1),
            (int) ($a['order_id'] ?? 1),
            (string) ($a['day'] ?? self::DAY),
            (int) ($a['hour'] ?? 9),
            (int) ($a['channel_id'] ?? 1),
            (string) ($a['channel_code'] ?? 'cash'),
            (string) ($a['amount'] ?? '100.00'),
            (string) ($a['status'] ?? 'recorded'),
            (bool) ($a['has_reference'] ?? false),
            (bool) ($a['has_receipt'] ?? false),
            (int) ($a['received_by_user_id'] ?? 30),
            $a['shift_instance_id'] ?? null,
            (string) ($a['received_at'] ?? self::DAY.'T06:00:00Z'),
            (bool) ($a['duplicate_reference'] ?? false),
        );
    }

    /** @param  array<string, mixed>  $a */
    public static function refund(array $a = []): RefundFact
    {
        return new RefundFact(
            (int) ($a['refund_id'] ?? 1),
            (int) ($a['order_id'] ?? 1),
            (int) ($a['payment_id'] ?? 1),
            (string) ($a['day'] ?? self::DAY),
            (int) ($a['channel_id'] ?? 1),
            (string) ($a['channel_code'] ?? 'cash'),
            (string) ($a['amount'] ?? '10.00'),
            (int) ($a['refunded_by_user_id'] ?? 30),
            $a['shift_instance_id'] ?? null,
            (string) ($a['refunded_at'] ?? self::DAY.'T10:00:00Z'),
            (int) ($a['order_refund_id'] ?? 1),
        );
    }

    /** @param  array<string, mixed>  $a */
    public static function batch(array $a = []): BatchFact
    {
        return new BatchFact(
            (int) ($a['batch_id'] ?? 1),
            (string) ($a['day'] ?? self::DAY),
            (int) ($a['station_id'] ?? 3),
            (int) ($a['recipe_id'] ?? 1),
            (string) ($a['status'] ?? 'finished'),
            Quantity::of((string) ($a['planned_qty'] ?? '12'))->toString(),
            Quantity::of((string) ($a['produced_qty'] ?? '12'))->toString(),
            Quantity::of((string) ($a['waste_qty'] ?? '0'))->toString(),
            (string) ($a['started_at'] ?? self::DAY.'T03:00:00Z'),
            (string) ($a['finished_at'] ?? self::DAY.'T05:00:00Z'),
            $a['shift_instance_id'] ?? null,
        );
    }

    /** @param  array<string, mixed>  $a */
    public static function movement(array $a = []): MovementFact
    {
        return new MovementFact(
            (int) ($a['movement_id'] ?? 1),
            (string) ($a['day'] ?? self::DAY),
            (int) ($a['item_id'] ?? 1),
            $a['from_location_id'] ?? null,
            $a['to_location_id'] ?? null,
            Quantity::of((string) ($a['qty'] ?? '1'))->toString(),
            (string) ($a['reason'] ?? 'purchase_in'),
            $a['ref_type'] ?? null,
            $a['ref_id'] ?? null,
            $a['shift_instance_id'] ?? null,
            (string) ($a['created_at'] ?? self::DAY.'T06:00:00Z'),
        );
    }

    public static function closing(int $itemId, int $locationId, string $onHand, string $reserved = '0'): ClosingBalanceFact
    {
        return new ClosingBalanceFact(self::DAY, $itemId, $locationId, Quantity::of($onHand)->toString(), Quantity::of($reserved)->toString());
    }

    public static function stockOut(int $id, int $itemId, ?int $stationId = null, ?int $locationId = 1): StockOutFact
    {
        return new StockOutFact($id, self::DAY, $itemId, $locationId, self::DAY.'T09:00:00Z', null, $stationId);
    }

    public static function unitCost(int $itemId, string $cost): UnitCostFact
    {
        return new UnitCostFact(self::DAY, $itemId, $cost, self::DAY.'T01:00:00Z');
    }

    /** @param  array<string, mixed>  $a */
    public static function shift(array $a = []): ShiftFact
    {
        $date = (string) ($a['date'] ?? self::DAY);

        return new ShiftFact(
            (int) ($a['shift_id'] ?? 1),
            $date,
            $date,
            (int) ($a['station_id'] ?? 1),
            (string) ($a['status'] ?? 'locked'),
            array_key_exists('opened_at', $a) ? $a['opened_at'] : $date.'T03:00:00Z',
            $a['closed_at'] ?? null,
            array_key_exists('locked_at', $a) ? $a['locked_at'] : $date.'T15:00:00Z',
            $a['cancelled_at'] ?? null,
            $a['opening_mode'] ?? 'carried',
            (int) ($a['variance_line_count'] ?? 0),
            $a['cash_variance'] ?? null,
        );
    }

    public static function item(int $id, string $kind = 'product', ?int $categoryId = null, string $fulfilment = 'to_order', string $unit = 'pcs'): ItemSummary
    {
        return new ItemSummary($id, "Item {$id}", "እቃ {$id}", $unit, $kind, $fulfilment, true, $categoryId);
    }

    /**
     * A recipe version of $productId: each line [ingredient id, qty in base units, waste %, optional?].
     *
     * @param  list<array{0: int, 1: string, 2?: string, 3?: bool}>  $lines
     */
    public static function recipe(int $productId, int $version, string $yield, array $lines): RecipeView
    {
        return new RecipeView(
            $productId,
            1000 + $version,
            $version,
            Quantity::of($yield),
            'pcs',
            array_map(static fn (array $l): RecipeLineView => new RecipeLineView($l[0], Quantity::of($l[1]), 'g', $l[3] ?? false, $l[2] ?? '0.00'), $lines),
        );
    }
}

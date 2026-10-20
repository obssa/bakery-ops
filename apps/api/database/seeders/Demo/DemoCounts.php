<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * What a branch holds, table by table (rows of the branch, soft-deleted ones included; items split by kind and
 * fulfilment over the live ones): what bakery:seed-demo reports and what the seeders' idempotence is checked against.
 * A read-only report, so it reads the tables directly (outside app/, where only the Ledger names the stock tables).
 */
final class DemoCounts
{
    private const array TABLES = [
        'stations', 'locations', 'categories', 'item_prices', 'item_station_assignments', 'recipes', 'recipe_lines',
        'recipe_steps', 'users', 'user_station_assignments', 'stock_movements', 'stock_balances', 'audit_log',
        'devices', 'dining_tables', 'payment_channels', 'orders', 'order_lines', 'order_events', 'payments',
    ];

    /**
     * @return array<string, int>
     */
    public static function of(int $branchId): array
    {
        $items = fn () => DB::table('items')->where('branch_id', $branchId)->whereNull('deleted_at');
        $counts = [
            'items' => $items()->count(),
            'ingredients' => $items()->where('kind', 'ingredient')->count(),
            'products' => $items()->where('kind', 'product')->count(),
            'products_to_order' => $items()->where('kind', 'product')->where('fulfilment', 'to_order')->count(),
            'products_to_stock' => $items()->where('kind', 'product')->where('fulfilment', 'to_stock')->count(),
            'products_bought_in' => $items()->where('kind', 'product')->where('fulfilment', 'bought_in')->count(),
        ];
        foreach (self::TABLES as $table) {
            $counts[$table] = DB::table($table)->where('branch_id', $branchId)->count();
        }

        return $counts;
    }
}

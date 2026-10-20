<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Expression;
use LogicException;

/**
 * The short daily order numbers the till and the kitchen call out (phase-1 plan section 3, migration 2): per branch
 * and business day (the branch's local date), from 1. One statement creates the day's order_counters row at 1 or
 * increments it (INSERT ... ON DUPLICATE KEY UPDATE on MySQL / MariaDB, ON CONFLICT DO UPDATE on PostgreSQL); either
 * way it holds that row's lock until the send commits, so two sends of the branch never take the same number and a
 * rolled-back send gives its number back. Called by SendOrder after the reservation, inside its transaction, so the
 * lock is held only for the last writes of the send.
 */
final class DailyNumbers
{
    public function __construct(private readonly ConnectionResolverInterface $db) {}

    public function next(int $branchId, string $businessDate): int
    {
        $connection = $this->db->connection();
        $now = CarbonImmutable::now();
        $connection->table('order_counters')->upsert(
            [['branch_id' => $branchId, 'business_date' => $businessDate, 'last_no' => 1, 'updated_at' => $now]],
            ['branch_id', 'business_date'],
            ['last_no' => new Expression('order_counters.last_no + 1'), 'updated_at' => $now],
        );

        $number = $connection->table('order_counters')
            ->where('branch_id', $branchId)
            ->where('business_date', $businessDate)
            ->lockForUpdate()
            ->value('last_no');

        return is_numeric($number) ? (int) $number : throw new LogicException('The day\'s order counter was not written.');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Modules\Inventory\Ledger\MovementReason;
use Brick\Math\BigDecimal;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The ledger's own reconciliation, on any connection (phase-5 plan section 2, contract ops->inventory, step 5.5b; ADR 0002): the restore
 * drill of App\Ops asks it about a database restored from last night's dump, through a named connection.
 *
 * check() adds the stock_movements rows up per (branch, item, place) by the rule of MovementReason (the reasons that need a
 * reservation_ref move only `reserved`; a consumption that settles one, a production_consume or sale with a reservation_ref, moves
 * both; every other row moves `on_hand`; the place of a row is the one end it names, or for a transfer row the side its sign points
 * at), and compares that with what stock_balances holds and with what the item_stock_view of that database computes. A pair at zero
 * on both sides is no pair, so a balance row created at zero and never moved equals no row. Every difference is a mismatch of its own,
 * named by its source (`stock_balances` or `item_stock_view`), ordered by source, branch, item and place, with both figures as
 * decimal strings at scale 4. The sums are SQL DECIMAL arithmetic and the comparison is brick/math: no float anywhere.
 *
 * It reads only the named connection, with three SELECTs, and writes nothing. A final class resolved per use (it carries nothing).
 */
final class LedgerOracle
{
    public function check(string $connection): LedgerCheck
    {
        $db = DB::connection($connection);
        $movements = $this->fold($db);
        $sources = [
            'item_stock_view' => $this->read($db, 'item_stock_view'),
            'stock_balances' => $this->read($db, 'stock_balances'),
        ];

        $items = [];
        $locations = [];
        $mismatches = [];
        foreach ($sources as $source => $recorded) {
            foreach (array_keys($movements + $recorded) as $key) {
                [$branchId, $itemId, $locationId] = array_map('intval', explode(':', $key));
                $items[$itemId] = true;
                $locations[$locationId] = true;
                $fold = $movements[$key] ?? ['0.0000', '0.0000'];
                $held = $recorded[$key] ?? ['0.0000', '0.0000'];
                if ($fold !== $held) {
                    $mismatches[] = [
                        'source' => $source,
                        'branch_id' => $branchId,
                        'item_id' => $itemId,
                        'location_id' => $locationId,
                        'movements' => ['on_hand' => $fold[0], 'reserved' => $fold[1]],
                        'recorded' => ['on_hand' => $held[0], 'reserved' => $held[1]],
                    ];
                }
            }
        }
        usort($mismatches, static fn (array $a, array $b): int => [$a['source'], $a['branch_id'], $a['item_id'], $a['location_id']]
            <=> [$b['source'], $b['branch_id'], $b['item_id'], $b['location_id']]);

        return new LedgerCheck(count($items), count($locations), $mismatches);
    }

    /**
     * The movements of every branch added up per pair, keyed "branch:item:location" => [on_hand, reserved].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function fold(ConnectionInterface $db): array
    {
        $reservedOnly = [];
        $settling = [];
        foreach (MovementReason::cases() as $reason) {
            if ($reason->needsReservationRef()) {
                $reservedOnly[] = $this->literal($reason->value);
            } elseif ($reason->canCarryReservationRef()) {
                $settling[] = $this->literal($reason->value);
            }
        }
        $only = implode(', ', $reservedOnly);
        $settle = implode(', ', $settling);
        $place = 'CASE WHEN from_location_id IS NULL THEN to_location_id WHEN to_location_id IS NULL THEN from_location_id '
            .'WHEN qty < 0 THEN from_location_id ELSE to_location_id END';
        $onHand = "CASE WHEN reason IN ({$only}) THEN 0 ELSE qty END";
        $reserved = "CASE WHEN reason IN ({$only}) THEN qty WHEN reason IN ({$settle}) AND reservation_ref IS NOT NULL THEN qty ELSE 0 END";

        // the place is computed in a derived table and grouped outside it: portable to PostgreSQL, which groups by an expression only verbatim
        $rows = $db->select(
            'SELECT m.branch_id, m.item_id, m.location_id, SUM(m.on_hand) AS on_hand, SUM(m.reserved) AS reserved '
            ."FROM (SELECT branch_id, item_id, {$place} AS location_id, {$onHand} AS on_hand, {$reserved} AS reserved FROM stock_movements) m "
            .'GROUP BY m.branch_id, m.item_id, m.location_id'
        );

        return $this->pairs($rows);
    }

    /**
     * What a table or view of (branch_id, item_id, location_id, on_hand, reserved) holds, keyed like fold().
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function read(ConnectionInterface $db, string $table): array
    {
        return $this->pairs($db->table($table)->get(['branch_id', 'item_id', 'location_id', 'on_hand', 'reserved'])->all());
    }

    /**
     * @param  array<int, mixed>  $rows  stdClass rows of (branch_id, item_id, location_id, on_hand, reserved)
     * @return array<string, array{0: string, 1: string}>
     */
    private function pairs(array $rows): array
    {
        $pairs = [];
        foreach ($rows as $row) {
            $onHand = (string) BigDecimal::of((string) $row->on_hand)->toScale(4);
            $reserved = (string) BigDecimal::of((string) $row->reserved)->toScale(4);
            if ($onHand === '0.0000' && $reserved === '0.0000') {
                continue;
            }
            $pairs[$row->branch_id.':'.$row->item_id.':'.$row->location_id] = [$onHand, $reserved];
        }

        return $pairs;
    }

    private function literal(string $value): string
    {
        if (preg_match('/^[a-z_]+$/', $value) !== 1) {
            throw new LogicException("A movement reason the oracle cannot quote: {$value}");
        }

        return "'{$value}'";
    }
}

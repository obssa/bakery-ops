<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/**
 * What LedgerOracle::check found on one connection: how many distinct items and places the ledger holds, and every pair whose
 * movements and recorded figures differ (contract ops->inventory). Ok when there is none.
 */
final readonly class LedgerCheck
{
    /**
     * @param  list<array{source: string, branch_id: int, item_id: int, location_id: int, movements: array{on_hand: string, reserved: string}, recorded: array{on_hand: string, reserved: string}}>  $mismatches
     */
    public function __construct(
        public int $items,
        public int $locations,
        public array $mismatches,
    ) {}

    public function ok(): bool
    {
        return $this->mismatches === [];
    }

    /**
     * @return array{ok: bool, items: int, locations: int, mismatches: list<array{source: string, branch_id: int, item_id: int, location_id: int, movements: array{on_hand: string, reserved: string}, recorded: array{on_hand: string, reserved: string}}>}
     */
    public function toArray(): array
    {
        return ['ok' => $this->ok(), 'items' => $this->items, 'locations' => $this->locations, 'mismatches' => $this->mismatches];
    }
}

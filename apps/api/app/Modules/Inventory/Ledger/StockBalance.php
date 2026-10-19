<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Ledger;

use App\Modules\Inventory\Contracts\StockLevel;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The running balance of one (item, location) (ADR 0002): the row SELECT ... FOR UPDATE locks, kept by Ledger in the
 * same transaction as every movement, used nowhere outside the Ledger namespace (readers get Contracts\StockLevel).
 * Updated in place by the Ledger, never deleted.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $item_id
 * @property int $location_id
 * @property Quantity $on_hand
 * @property Quantity $reserved
 * @property CarbonImmutable|null $updated_at
 */
final class StockBalance extends Model
{
    use BranchScoped;

    public const CREATED_AT = null;

    protected $table = 'stock_balances';

    /** @var list<string> nothing is mass assignable: the Ledger sets every column explicitly */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::deleting(function (): never {
            throw StockLedgerIsAppendOnly::forOperation('stock_balances', 'delete');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'item_id' => 'integer',
            'location_id' => 'integer',
            'on_hand' => Quantity::class,
            'reserved' => Quantity::class,
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function toLevel(): StockLevel
    {
        return new StockLevel($this->item_id, $this->location_id, $this->on_hand, $this->reserved);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Ledger;

use App\Modules\Inventory\Contracts\Movement;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the append-only stock ledger (rule 1, ADR 0002). Written by Ledger only and used nowhere outside the
 * Ledger namespace (tests/Arch/LedgerIsSoleWriterTest.php): readers get Contracts\Movement through toMovement(). The
 * model refuses update and delete; the database triggers refuse them too. No updated_at, no soft delete.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $item_id
 * @property int|null $from_location_id
 * @property int|null $to_location_id
 * @property Quantity $qty
 * @property MovementReason $reason
 * @property string $ref_type
 * @property int|null $ref_id
 * @property string|null $reservation_ref
 * @property int|null $shift_instance_id
 * @property int $user_id
 * @property int|null $acting_user_id
 * @property int|null $station_id
 * @property int|null $device_id
 * @property string|null $client_uuid
 * @property CarbonImmutable $created_at
 */
final class StockMovement extends Model
{
    use BranchScoped;

    public const UPDATED_AT = null;

    protected $table = 'stock_movements';

    /** @var list<string> nothing is mass assignable: the Ledger sets every column explicitly */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw StockLedgerIsAppendOnly::forOperation('stock_movements', 'update');
        });
        self::deleting(function (): never {
            throw StockLedgerIsAppendOnly::forOperation('stock_movements', 'delete');
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
            'from_location_id' => 'integer',
            'to_location_id' => 'integer',
            'qty' => Quantity::class,
            'reason' => MovementReason::class,
            'ref_id' => 'integer',
            'shift_instance_id' => 'integer',
            'user_id' => 'integer',
            'acting_user_id' => 'integer',
            'station_id' => 'integer',
            'device_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** The location whose balance this row changed: the one end it names, or for a transfer the side its sign picks. */
    public function locationId(): int
    {
        if ($this->from_location_id === null) {
            return (int) $this->to_location_id;
        }
        if ($this->to_location_id === null) {
            return $this->from_location_id;
        }

        return $this->qty->isNegative() ? $this->from_location_id : $this->to_location_id;
    }

    public function toMovement(): Movement
    {
        $effect = $this->reason->effect($this->qty, $this->reservation_ref !== null);

        return new Movement(
            $this->id,
            $this->branch_id,
            $this->item_id,
            $this->locationId(),
            $this->from_location_id,
            $this->to_location_id,
            $this->qty,
            $this->reason,
            $effect->onHand,
            $effect->reserved,
            $this->ref_type,
            $this->ref_id,
            $this->reservation_ref,
            $this->shift_instance_id,
            $this->user_id,
            $this->acting_user_id,
            $this->station_id,
            $this->device_id,
            $this->client_uuid,
            $this->created_at,
        );
    }
}

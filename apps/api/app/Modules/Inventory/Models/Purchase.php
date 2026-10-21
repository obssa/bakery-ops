<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\PurchaseStatus;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Database\Factories\Inventory\PurchaseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A purchase from a supplier (phase-2 plan section 3, migration 4). Its status moves only along PurchaseStatus, through the
 * actions of step 2.2b (never a PATCH of status). Never deleted: draft, then confirmed or cancelled (no SoftDeletes,
 * tests/Arch/SoftDeletesTest.php allowlist). total is Money in the row's currency, the sum of the lines' line_total.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $client_uuid
 * @property int $supplier_id
 * @property string|null $invoice_no
 * @property CarbonImmutable $purchase_date
 * @property int $location_id
 * @property PurchaseStatus $status
 * @property string $currency
 * @property Money $total
 * @property string|null $notes
 * @property CarbonImmutable|null $confirmed_at
 * @property int|null $confirmed_by_user_id
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property int $created_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $actor_station_id
 * @property int|null $device_id
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Supplier $supplier
 * @property-read Location $location
 * @property-read User|null $confirmedBy
 * @property-read Collection<int, PurchaseLine> $lines
 */
final class Purchase extends Model
{
    use BranchScoped;

    /** @use HasFactory<PurchaseFactory> */
    use HasFactory;

    protected $table = 'purchases';

    /** @var list<string> what a client chooses; status, money, the confirmation and the actor stamp are set by the actions */
    protected $fillable = ['client_uuid', 'supplier_id', 'invoice_no', 'purchase_date', 'location_id', 'currency', 'notes'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'currency' => Money::DEFAULT_CURRENCY,
        'total' => '0.00',
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'supplier_id' => 'integer',
            'purchase_date' => 'immutable_date',
            'location_id' => 'integer',
            'status' => PurchaseStatus::class,
            'total' => Money::class,
            'confirmed_at' => 'immutable_datetime',
            'confirmed_by_user_id' => 'integer',
            'cancelled_at' => 'immutable_datetime',
            'created_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'shift_instance_id' => 'integer',
            'actor_station_id' => 'integer',
            'device_id' => 'integer',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    /**
     * @return HasMany<PurchaseLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class, 'purchase_id')->orderBy('id');
    }

    protected static function newFactory(): PurchaseFactory
    {
        return PurchaseFactory::new();
    }
}

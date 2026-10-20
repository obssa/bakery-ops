<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Orders\OrderCounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The order numbers of one branch on one business day (its local date): SendOrder (step 1.3b) locks the row FOR
 * UPDATE, increments last_no and gives the order that number. Updated in place, never deleted (no SoftDeletes,
 * tests/Arch/SoftDeletesTest.php allowlist), no created_at.
 *
 * @property int $id
 * @property int $branch_id
 * @property CarbonImmutable $business_date
 * @property int $last_no
 * @property CarbonImmutable|null $updated_at
 */
final class OrderCounter extends Model
{
    use BranchScoped;

    /** @use HasFactory<OrderCounterFactory> */
    use HasFactory;

    public const CREATED_AT = null;

    protected $table = 'order_counters';

    /** @var list<string> */
    protected $fillable = ['business_date', 'last_no'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'last_no' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'business_date' => 'immutable_date',
            'last_no' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): OrderCounterFactory
    {
        return OrderCounterFactory::new();
    }
}

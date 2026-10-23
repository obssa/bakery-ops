<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Models;

use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cash of the till's drawer for one cash-drawer count (phase-3 plan section 3, migration 13): what the cashier counted by
 * denomination, the total the server worked out, and what it is measured against. It has no lifecycle of its own: the count it belongs to
 * is the draft, the submission, the approval, the lock and the link of the chain. Frozen by database trigger once that count is
 * submitted, never deleted (no SoftDeletes, tests/Arch/SoftDeletesTest.php allowlist), and it carries no actor stamp because the count
 * carries it (ActorStampTest allowlist). Money is Money (brick/math decimals, ADR 0004). The shift, the reason and the count are plain ids
 * or relations inside Shifts.
 *
 * `denominations` is the object {"200": 3, "100": 5, ..} of every configured denomination; `channel_counts` is the declared figures of the
 * reconciliation while the count is a draft ([{code, receipts, sum}]) and the full table from the submit on ([{code, expected_receipts,
 * expected_sum, declared_receipts, declared_sum, difference_sum}], ordered by code).
 *
 * @property int $id
 * @property int $branch_id
 * @property int $count_id
 * @property int $shift_instance_id
 * @property array<string, int> $denominations
 * @property Money $counted_total
 * @property Money|null $expected_total
 * @property Money|null $variance_total
 * @property Money|null $carried_float_total
 * @property Money|null $cash_sales_total
 * @property Money|null $float_left_total
 * @property list<array<string, mixed>>|null $channel_counts
 * @property int|null $variance_reason_id
 * @property string|null $variance_note
 * @property string $currency
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read StockCount $stockCount
 */
final class CashDeclaration extends Model
{
    use BranchScoped;

    protected $table = 'cash_declarations';

    /** @var list<string> nothing: a declaration is written by the actions */
    protected $fillable = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'ETB',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'count_id' => 'integer',
            'shift_instance_id' => 'integer',
            'denominations' => 'array',
            'counted_total' => Money::class,
            'expected_total' => Money::class,
            'variance_total' => Money::class,
            'carried_float_total' => Money::class,
            'cash_sales_total' => Money::class,
            'float_left_total' => Money::class,
            'channel_counts' => 'array',
            'variance_reason_id' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<StockCount, $this>
     */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'count_id');
    }
}

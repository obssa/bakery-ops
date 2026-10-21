<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One episode of an item running out (type `out`) or low (type `low`) at a place (plan section 3; ADR 0011). Written
 * only by Alerts\AlertEvaluator under the balance row's lock: opened with is_active = 1, closed by setting cleared_at and
 * is_active NULL. Never deleted, softly or otherwise: episodes are the history of every alert and the unique key
 * (item_id, location_key, type, is_active) is what lets only one stay open.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $item_id
 * @property int|null $location_id
 * @property int $location_key
 * @property string $type
 * @property int|null $is_active
 * @property CarbonImmutable $active_since
 * @property CarbonImmutable|null $cleared_at
 * @property Quantity $available_at_start
 * @property Quantity|null $threshold_qty
 * @property int|null $caused_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class StockAlertState extends Model
{
    use BranchScoped;

    public const string OUT = 'out';

    public const string LOW = 'low';

    protected $table = 'stock_alert_states';

    /** @var list<string> nothing is mass assignable: the evaluator sets every column explicitly */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'item_id' => 'integer',
            'location_id' => 'integer',
            'location_key' => 'integer',
            'is_active' => 'integer',
            'active_since' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
            'available_at_start' => Quantity::class,
            'threshold_qty' => Quantity::class,
            'caused_by_user_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

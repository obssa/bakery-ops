<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The delivery gate of one episodic alert (plan section 3, migration 13; ADR 0011): active while people have been told and
 * a repeat must be held, cleared when StockRecovered arrives, kept as history. Written only by Support\Dedupe, which claims it
 * with one insert the unique key (branch_id, dedupe_key, is_active) arbitrates.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $dedupe_key
 * @property string $type
 * @property int|null $is_active
 * @property CarbonImmutable $active_since
 * @property CarbonImmutable|null $cleared_at
 */
final class NotificationDedupe extends Model
{
    use BranchScoped;

    protected $table = 'notification_dedupe';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var list<string> nothing is mass assignable: Support\Dedupe sets every column explicitly */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'is_active' => 'integer',
            'active_since' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
        ];
    }
}

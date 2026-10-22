<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Models;

use App\Modules\Shifts\Support\TemplateWindow;
use App\Shared\I18n\Bilingual;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Shifts\ShiftTemplateFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named window of the day on the days of the week (phase-3 plan section 3, migration 3): "Day 06:00 to 14:00", "Night 22:00
 * to 06:00". start_time and end_time are the branch's local wall-clock times; an end earlier than the start is the
 * cross-midnight window. days_mask bit 0 is Monday .. bit 6 Sunday, the ISO weekday of the day the window starts.
 * Configuration: soft-deleted, audited; editing it never changes an instance already made.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name_en
 * @property string $name_am
 * @property Bilingual|null $name
 * @property string $start_time HH:MM:SS
 * @property string $end_time HH:MM:SS
 * @property int $days_mask
 * @property bool $is_active
 * @property int $sort
 * @property int $created_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $actor_station_id
 * @property int|null $device_id
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Collection<int, ShiftTemplateStation> $stations
 */
final class ShiftTemplate extends Model
{
    use BranchScoped;

    /** @use HasFactory<ShiftTemplateFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'shift_templates';

    /** @var list<string> */
    protected $fillable = ['name_en', 'name_am', 'start_time', 'end_time', 'days_mask', 'is_active', 'sort'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'days_mask' => 127,
        'is_active' => true,
        'sort' => 0,
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'name' => Bilingual::class,
            'days_mask' => 'integer',
            'is_active' => 'boolean',
            'sort' => 'integer',
            'created_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'shift_instance_id' => 'integer',
            'actor_station_id' => 'integer',
            'device_id' => 'integer',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /** The pure value ShiftWindow works on. */
    public function window(): TemplateWindow
    {
        return new TemplateWindow($this->id, $this->start_time, $this->end_time, $this->days_mask);
    }

    /**
     * @return HasMany<ShiftTemplateStation, $this>
     */
    public function stations(): HasMany
    {
        return $this->hasMany(ShiftTemplateStation::class, 'template_id');
    }

    protected static function newFactory(): ShiftTemplateFactory
    {
        return ShiftTemplateFactory::new();
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Models;

use App\Shared\Events\BranchCreated;
use Carbon\CarbonImmutable;
use Database\Factories\Shared\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The tenant. Timezone and currency default to the bakery's (Africa/Addis_Ababa, ETB); settings is free-form JSON (the empty
 * object by default: MySQL allows no literal default on JSON columns, so the model supplies it).
 *
 * @property int $id
 * @property string $name
 * @property string $timezone
 * @property string $currency
 * @property array<string, mixed> $settings
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'branches';

    /** @var list<string> */
    protected $fillable = ['name', 'timezone', 'currency', 'settings'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'timezone' => 'Africa/Addis_Ababa',
        'currency' => 'ETB',
        'settings' => '{}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<Station, $this>
     */
    public function stations(): HasMany
    {
        return $this->hasMany(Station::class, 'branch_id');
    }

    /**
     * A new branch announces itself (BranchCreated, phase-1 plan section 5), synchronously and in the transaction that
     * creates it, so the modules that give every branch its starting rows (Payments: the eight channels) do it
     * wherever branches come from: the admin command, the seeders, the factories. Nothing is announced for an update.
     */
    protected static function booted(): void
    {
        self::created(static function (self $branch): void {
            event(new BranchCreated($branch->id));
        });
    }

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }
}

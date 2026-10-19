<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Shared\I18n\Bilingual;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Database\Factories\Catalog\UnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A unit of measure (domain-model.md, Catalog). Global reference data shared by every branch, so it carries no
 * branch_id and no BranchScoped (tests/Arch/BranchScopedTest.php allowlist). A base unit (g, ml, pcs) has no base and
 * factor 1; a derived unit converts to its base by factor (kg: 1000 g). Conversions live in Contracts\Units.
 *
 * @property int $id
 * @property string $code
 * @property string $name_en
 * @property string $name_am
 * @property Bilingual|null $name
 * @property int|null $base_unit_id
 * @property Quantity $factor
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Unit|null $baseUnit
 */
final class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'units';

    /** @var list<string> */
    protected $fillable = ['code', 'name_en', 'name_am', 'base_unit_id', 'factor'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_unit_id' => 'integer',
            'factor' => Quantity::class,
            'name' => Bilingual::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * The base unit this one converts to; a base unit is never deleted, but a soft-deleted one must still convert.
     *
     * @return BelongsTo<Unit, $this>
     */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_unit_id')->withTrashed();
    }

    public function isBase(): bool
    {
        return $this->base_unit_id === null;
    }

    protected static function newFactory(): UnitFactory
    {
        return UnitFactory::new();
    }
}

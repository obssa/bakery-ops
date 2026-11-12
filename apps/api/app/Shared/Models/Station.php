<?php

declare(strict_types=1);

namespace App\Shared\Models;

use App\Shared\Enums\StationCode;
use App\Shared\I18n\Bilingual;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Shared\StationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Where order lines route and who counts what: one row per StationCode per branch.
 *
 * @property int $id
 * @property int $branch_id
 * @property StationCode $code
 * @property string $name_en
 * @property string $name_am
 * @property Bilingual|null $name
 * @property bool $requires_overseer
 * @property bool $tracks_shifts
 * @property bool $count_opening a shift starts with an opening count (else it opens at once, carried); the counting rules, ADR 0026
 * @property bool $count_cosign a handed-in count waits for a second person's co-signature
 * @property bool $count_approval a count that differs waits for a manager's decision (else the rule approves it)
 * @property int $sort
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class Station extends Model
{
    use BranchScoped;

    /** @use HasFactory<StationFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'stations';

    /** @var list<string> */
    protected $fillable = ['branch_id', 'code', 'name', 'name_en', 'name_am', 'requires_overseer', 'tracks_shifts', 'count_opening', 'count_cosign', 'count_approval', 'sort'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'requires_overseer' => false,
        'tracks_shifts' => true,
        'count_opening' => true,
        'count_cosign' => false,
        'count_approval' => true,
        'sort' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => StationCode::class,
            'name' => Bilingual::class,
            'requires_overseer' => 'boolean',
            'tracks_shifts' => 'boolean',
            'count_opening' => 'boolean',
            'count_cosign' => 'boolean',
            'count_approval' => 'boolean',
            'sort' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * The station's counting rules as every answer shows them (ADR 0026): `opening_count` (a shift starts by counting), `cosign` (a handed-in
     * count waits for a second person), `approval` (a count that differs waits for a manager).
     *
     * @return array{opening_count: bool, cosign: bool, approval: bool}
     */
    public function countingRules(): array
    {
        return ['opening_count' => $this->count_opening, 'cosign' => $this->count_cosign, 'approval' => $this->count_approval];
    }

    protected static function newFactory(): StationFactory
    {
        return StationFactory::new();
    }
}

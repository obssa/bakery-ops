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
    protected $fillable = ['branch_id', 'code', 'name', 'name_en', 'name_am', 'requires_overseer', 'sort'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'requires_overseer' => false,
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
            'sort' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): StationFactory
    {
        return StationFactory::new();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Shared\I18n\Bilingual;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Orders\DiningTableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A table of the dining room (domain-model.md, Orders): the pad's table grid. code is unique per branch and is what
 * an order snapshots as table_code; the table's state (free, ordering, waiting, served, bill) is computed from its
 * open orders (step 1.5), never stored. Configuration the admin edits (tables.manage): soft-deleted, created_by_user_id
 * written once at creation.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $code
 * @property string $name_en
 * @property string $name_am
 * @property Bilingual|null $name
 * @property int|null $seats
 * @property int $sort
 * @property bool $is_active
 * @property int $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class DiningTable extends Model
{
    use BranchScoped;

    /** @use HasFactory<DiningTableFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'dining_tables';

    /** @var list<string> the editable attributes; branch_id and created_by_user_id are set explicitly on create */
    protected $fillable = ['code', 'name_en', 'name_am', 'seats', 'sort', 'is_active'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'sort' => 0,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'name' => Bilingual::class,
            'seats' => 'integer',
            'sort' => 'integer',
            'is_active' => 'boolean',
            'created_by_user_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): DiningTableFactory
    {
        return DiningTableFactory::new();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Catalog\Models\Modifier;
use App\Shared\I18n\Bilingual;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Database\Factories\Orders\OrderLineModifierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A modifier of an order line as it was when the line was written (domain-model.md, Orders): the bilingual name, the
 * price delta per unit (Money) and the recipe delta per unit in base units, `[{ingredient_item_id, qty, unit}]` with
 * qty a decimal string, so a later edit of the Catalog modifier never changes a line already sold. Written once with
 * its line: no updated_at, never deleted (SoftDeletesTest allowlist).
 *
 * @property int $id
 * @property int $branch_id
 * @property int $order_line_id
 * @property int $modifier_id
 * @property string $name_en
 * @property string $name_am
 * @property Bilingual|null $name
 * @property Money $price_delta_snapshot
 * @property list<array{ingredient_item_id: int, qty: string, unit: string}> $recipe_delta_snapshot
 * @property CarbonImmutable|null $created_at
 * @property-read OrderLine $line
 * @property-read Modifier $modifier
 */
final class OrderLineModifier extends Model
{
    use BranchScoped;

    /** @use HasFactory<OrderLineModifierFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'order_line_modifiers';

    /** @var list<string> nothing is mass assignable: the snapshot is written explicitly with its line */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'order_line_id' => 'integer',
            'modifier_id' => 'integer',
            'name' => Bilingual::class,
            'price_delta_snapshot' => Money::class,
            'recipe_delta_snapshot' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<OrderLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    /**
     * @return BelongsTo<Modifier, $this>
     */
    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class, 'modifier_id');
    }

    protected static function newFactory(): OrderLineModifierFactory
    {
        return OrderLineModifierFactory::new();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Shared\I18n\Bilingual;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Payments\PaymentChannelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A way the branch takes money (domain-model.md, Payments; docs/phases/phase-1-plan.md section 3, migration 7). The eight
 * defaults are given to every branch (Channels::ensureDefaults); the admin may switch one off (is_enabled) or remove it
 * (soft delete: the payments made on it keep pointing at it). requires_photo and requires_reference say what a payment
 * on it must come with (from step 4.3b the photo is attached to the payment, so every enabled channel is accepted: acceptedNow). Step 4.3a (phase-4 plan
 * M3) adds the admin's own channels (client_uuid, NULL for the defaults), a reference pattern (Support\ReferencePattern) and the
 * bilingual hints the till shows; the cash channel (code `cash`) is locked to taking cash (Support\ChannelRules).
 *
 * @property int $id
 * @property int $branch_id
 * @property string|null $client_uuid
 * @property string $code
 * @property string $name_en
 * @property string $name_am
 * @property Bilingual|null $name
 * @property bool $is_enabled
 * @property bool $requires_photo
 * @property bool $requires_reference
 * @property string|null $reference_pattern
 * @property string|null $reference_hint_en
 * @property string|null $reference_hint_am
 * @property string|null $photo_hint_en
 * @property string|null $photo_hint_am
 * @property int $sort
 * @property int|null $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class PaymentChannel extends Model
{
    use BranchScoped;

    /** @use HasFactory<PaymentChannelFactory> */
    use HasFactory;

    use SoftDeletes;

    /** The code of the cash channel: every branch has it, and it cannot be switched off, removed or given a photo or reference rule. */
    public const string CASH = 'cash';

    protected $table = 'payment_channels';

    /** @var list<string> */
    protected $fillable = ['code', 'name_en', 'name_am', 'is_enabled', 'requires_photo', 'requires_reference', 'sort'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_enabled' => true,
        'requires_photo' => false,
        'requires_reference' => false,
        'sort' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'name' => Bilingual::class,
            'is_enabled' => 'boolean',
            'requires_photo' => 'boolean',
            'requires_reference' => 'boolean',
            'sort' => 'integer',
            'created_by_user_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /** Whether the till can take money on it today: enabled (step 4.3b; before it, a channel that needs a receipt photo was not). */
    public function acceptedNow(): bool
    {
        return $this->is_enabled;
    }

    /** The one channel whose money is counted in the drawer (Totals::cash, RecordPayment's tendered cash and change). */
    public function isCash(): bool
    {
        return $this->code === self::CASH;
    }

    protected static function newFactory(): PaymentChannelFactory
    {
        return PaymentChannelFactory::new();
    }
}

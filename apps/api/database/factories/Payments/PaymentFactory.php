<?php

declare(strict_types=1);

namespace Database\Factories\Payments;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A recorded cash payment of 100.00, tendered exactly, on an open order of a fresh branch (a row only: it moves nothing
 * on the order). The channel is the branch's cash channel; received_by_user_id is the authenticated actor when there is
 * one, else a fresh user of the branch (rule 4: no row without a person).
 *
 * @extends Factory<Payment>
 */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'client_uuid' => fn (): string => (string) Str::uuid(),
            'order_id' => fn (array $attributes): int => Order::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'channel_id' => fn (array $attributes): int => (int) PaymentChannel::query()->withoutGlobalScopes()
                ->where('branch_id', $attributes['branch_id'])->where('code', 'cash')->value('id'),
            'amount' => '100.00',
            'tendered_amount' => '100.00',
            'change_amount' => '0.00',
            'currency' => 'ETB',
            'reference_no' => null,
            'receipt_photo_key' => null,
            'status' => 'recorded',
            'received_by_user_id' => fn (array $attributes): int => app(ActorContext::class)->userId()
                ?? User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'acting_user_id' => null,
            'shift_instance_id' => null,
            'station_id' => null,
            'device_id' => null,
            'received_at' => CarbonImmutable::now()->startOfSecond(),
        ];
    }
}

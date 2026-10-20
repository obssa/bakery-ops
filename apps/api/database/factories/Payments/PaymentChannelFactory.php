<?php

declare(strict_types=1);

namespace Database\Factories\Payments;

use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An extra enabled channel of a fresh branch with a unique code, no photo and no reference required (the eight defaults
 * come with every branch from BranchCreated, so tests that want a different channel make this one).
 *
 * @extends Factory<PaymentChannel>
 */
final class PaymentChannelFactory extends Factory
{
    protected $model = PaymentChannel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 999999);

        return [
            'branch_id' => Branch::factory(),
            'code' => "channel_{$n}",
            'name_en' => "Channel {$n}",
            'name_am' => "መንገድ {$n}",
            'is_enabled' => true,
            'requires_photo' => false,
            'requires_reference' => false,
            'sort' => 100,
            'created_by_user_id' => null,
        ];
    }

    public function disabled(): self
    {
        return $this->state(fn (): array => ['is_enabled' => false]);
    }

    public function digital(): self
    {
        return $this->state(fn (): array => ['requires_photo' => true, 'requires_reference' => true]);
    }
}

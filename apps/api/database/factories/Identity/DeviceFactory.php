<?php

declare(strict_types=1);

namespace Database\Factories\Identity;

use App\Modules\Identity\Models\Device;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
final class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => 'Pad '.fake()->unique()->numberBetween(1, 9999),
            'fingerprint' => (string) Str::uuid(),
            'enrolled_by' => null,
            'enrolled_at' => CarbonImmutable::now(),
            'revoked_at' => null,
        ];
    }

    public function revoked(): self
    {
        return $this->state(fn (): array => ['revoked_at' => CarbonImmutable::now()]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories\Identity;

use App\Modules\Identity\Models\ActingSession;
use App\Modules\Identity\Models\User;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActingSession>
 */
final class ActingSessionFactory extends Factory
{
    protected $model = ActingSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'branch_id' => Branch::factory(),
            'user_id' => User::factory(),
            'device_id' => null,
            'started_at' => $now,
            'expires_at' => $now->addMinutes(10),
            'ended_at' => null,
        ];
    }

    public function expired(): self
    {
        return $this->state(fn (): array => [
            'started_at' => CarbonImmutable::now()->subMinutes(11),
            'expires_at' => CarbonImmutable::now()->subMinute(),
        ]);
    }

    public function ended(): self
    {
        return $this->state(fn (): array => ['ended_at' => CarbonImmutable::now()]);
    }
}

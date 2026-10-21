<?php

declare(strict_types=1);

namespace Database\Factories\Notifications;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A live web push subscription for a fresh user of the same branch; push() and telegram() pick the kind. The address is made
 * up (an example host, random keys): nothing here reaches a real push service. forceFill because the model allows no mass
 * assignment.
 *
 * @extends Factory<NotificationEndpoint>
 */
final class NotificationEndpointFactory extends Factory
{
    protected $model = NotificationEndpoint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'user_id' => fn (array $attributes): int => User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'driver' => 'push',
            'address' => json_encode([
                'endpoint' => 'https://push.example.test/send/'.Str::random(24),
                'keys' => ['p256dh' => Str::random(87), 'auth' => Str::random(22)],
            ], JSON_THROW_ON_ERROR),
            'user_agent' => null,
            'verified_at' => now(),
            'last_success_at' => null,
            'revoked_at' => null,
        ];
    }

    public function push(): self
    {
        return $this->state(fn (): array => ['driver' => 'push']);
    }

    public function telegram(): self
    {
        return $this->state(fn (): array => ['driver' => 'telegram', 'address' => (string) random_int(100000000, 999999999)]);
    }

    public function newModel(array $attributes = []): NotificationEndpoint
    {
        return (new NotificationEndpoint)->forceFill($attributes);
    }
}

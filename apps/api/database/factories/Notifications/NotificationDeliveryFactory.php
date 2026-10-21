<?php

declare(strict_types=1);

namespace Database\Factories\Notifications;

use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A pending push delivery of a fresh inbox line to a fresh endpoint of the same person and branch. A row only: the planner
 * writes the real thing. forceFill because the model allows no mass assignment.
 *
 * @extends Factory<NotificationDelivery>
 */
final class NotificationDeliveryFactory extends Factory
{
    protected $model = NotificationDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'notification_id' => fn (array $attributes): int => Notification::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'driver' => 'push',
            'endpoint_id' => fn (array $attributes): int => NotificationEndpoint::factory()->create([
                'branch_id' => $attributes['branch_id'],
                'user_id' => (int) Notification::query()->withoutGlobalScopes()->whereKey($attributes['notification_id'])->value('user_id'),
                'driver' => $attributes['driver'],
            ])->id,
            'status' => 'pending',
            'attempts' => 0,
            'last_error' => null,
            'sent_at' => null,
        ];
    }

    public function newModel(array $attributes = []): NotificationDelivery
    {
        return (new NotificationDelivery)->forceFill($attributes);
    }
}

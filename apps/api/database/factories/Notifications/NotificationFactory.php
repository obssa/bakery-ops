<?php

declare(strict_types=1);

namespace Database\Factories\Notifications;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\Notification;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An unread stock-out line for a fresh user of the same branch, in both languages, created now. A row only: the Notifier
 * writes the real thing. forceFill because the model allows no mass assignment.
 *
 * @extends Factory<Notification>
 */
final class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'user_id' => fn (array $attributes): int => User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'type' => 'stock_out',
            'severity' => 'critical',
            'title_en' => 'Flour is out of stock',
            'title_am' => 'ዱቄት አልቋል',
            'body_en' => 'None left at Store.',
            'body_am' => 'በመጋዘን ምንም አልቀረም።',
            'payload' => ['item_id' => 1, 'location_id' => 1],
            'deep_link' => '/inventory?item=1&location=1',
            'dedupe_key' => null,
            'read_at' => null,
            'created_at' => CarbonImmutable::now(),
        ];
    }

    public function read(): self
    {
        return $this->state(fn (): array => ['read_at' => CarbonImmutable::now()]);
    }

    /**
     * The model allows no mass assignment, so the factory fills it by force.
     */
    public function newModel(array $attributes = []): Notification
    {
        return (new Notification)->forceFill($attributes);
    }
}

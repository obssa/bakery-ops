<?php

declare(strict_types=1);

namespace Database\Factories\Identity;

use App\Modules\Identity\Models\User;
use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->name(),
            'phone' => '+2519'.fake()->unique()->numerify('########'),
            'email' => null,
            'password' => self::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'locale' => 'en',
            'is_active' => true,
            'photo_url' => null,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function amharic(): self
    {
        return $this->state(fn (): array => ['locale' => 'am']);
    }
}

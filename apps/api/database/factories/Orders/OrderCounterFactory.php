<?php

declare(strict_types=1);

namespace Database\Factories\Orders;

use App\Modules\Orders\Models\OrderCounter;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The counter of a fresh branch for today in Addis Ababa (the default branch time zone), nothing numbered yet.
 *
 * @extends Factory<OrderCounter>
 */
final class OrderCounterFactory extends Factory
{
    protected $model = OrderCounter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'business_date' => CarbonImmutable::now('Africa/Addis_Ababa')->toDateString(),
            'last_no' => 0,
        ];
    }
}

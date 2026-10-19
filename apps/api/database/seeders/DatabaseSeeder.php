<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * `php artisan db:seed`: the roles and permissions of the matrix (idempotent), then the demo bakery phase by phase.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesSeeder::class);
        $this->call(DemoSeeder::class);
    }
}

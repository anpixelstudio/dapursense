<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. Users and tenants are created through
     * registration or `php artisan system:install`.
     */
    public function run(): void
    {
        $this->call(SystemSeeder::class);
    }
}

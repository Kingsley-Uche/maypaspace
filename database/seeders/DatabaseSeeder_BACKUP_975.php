<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserTypeSeeder::class,
            RolesTableSeeder::class,
            AdminsTableSeeder::class,
            RolesTableSeeder::class,
            AdminsTableSeeder::class,
            UserTypeSeeder::class,
            CategorySeeder::class,
        ]);
    }
}

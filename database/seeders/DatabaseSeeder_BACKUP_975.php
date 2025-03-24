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
<<<<<<< HEAD
            UserTypeSeeder::class,
            RolesTableSeeder::class,
            AdminsTableSeeder::class,
=======
            RolesTableSeeder::class,
            AdminsTableSeeder::class,
            UserTypeSeeder::class,
>>>>>>> 756d12202238f2e4d4e0ed3926fda384cb31b0d6
            ProductTypeSeeder::class,
            CategorySeeder::class,
        ]);
    }
}

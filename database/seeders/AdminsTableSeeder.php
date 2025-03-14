<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table("admins")->insert([
            [
              'id' => 1,
              'first_name' => 'Emeka',
              'last_name'=> 'David',
              'email'=> 'admin@ffsd.com',
              'role_id' => 1,
              'password'=> Hash::make('testingPassword'),
            ]
        ]);
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table("user_types")->insert([
            [
              'user_type' => 'Owner',
              'created_by_user_id' => null,
              'create_admin'=> 'yes',
              'update_admin'=> 'yes',
              'view_admin'=> 'yes',
              'delete_admin'=>'yes',
              'create_user'=> 'yes',
              'update_user'=> 'yes',
              'view_user'=> 'yes',
              'delete_user'=>'yes',
              'create_location'=> 'yes',
              'update_location'=> 'yes',
              'view_location'=> 'yes',
              'delete_location'=>'yes',
              'create_floor'=> 'yes',
              'update_floor'=> 'yes',
              'view_floor'=> 'yes',
              'delete_floor'=>'yes',
              'create_space'=> 'yes',
              'update_space'=> 'yes',
              'view_space'=> 'yes',
              'delete_space'=>'yes',
              'create_booking'=> 'yes',
              'update_booking'=> 'yes',
              'view_booking'=> 'yes',
              'delete_booking'=>'yes',
              'created_at' => now(),
              'updated_at' => now(),
            ],
        ]);
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RolesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table("roles")->insert([
            [
              'id' => 1,
              'role' => 'owner',
              'create_tenant'=> 'yes',
              'update_tenant'=> 'yes',
              'view_tenant'=> 'yes',
              'delete_tenant'=>'yes',
              'view_tenant_income'=> 'yes',
              'create_plan' => 'yes',
            ]
        ]);
    }
}

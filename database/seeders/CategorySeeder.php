<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('categories')->insert([
            [
             'category' => 'Co-Workspace',
             'booking_type' => 'daily',
            ],

            [
            'category' => 'Private Office',
            'booking_type' => 'monthly',
            ],

            

            [
            'category' => 'Dedicated Desk',
            'booking_type' => 'monthly',
            ],

            [
            'category' => 'Conference Room',
            'booking_type' => 'hourly',
            ],

            [
            'category' => 'Office Pod',
            'booking_type' => 'hourly',
            ],


            [
            'category' => 'Event Space',
            'booking_type' => 'daily',
            ],
            
         ]);
    }
}

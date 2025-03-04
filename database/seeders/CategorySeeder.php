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
            ],

            [
            'category' => 'Private Office',
            ],

            [
            'category' => 'Dedicated Desk',
            ],

            [
            'category' => 'Conference Room',
            ],

            [
            'category' => 'Office Pod',
            ],

            [
            'category' => 'Event Space',
            ],
            
         ]);
    }
}

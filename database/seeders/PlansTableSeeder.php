<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlansTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('plans')->insert([
           [
            'name' => 'Monthly',
            'price'=> 10000,
            'duration'=> 1,
           ],

           [
            'name' => 'Yearly',
            'price'=> 100000,
            'duration'=> 12,
           ]
        ]);
    }
}

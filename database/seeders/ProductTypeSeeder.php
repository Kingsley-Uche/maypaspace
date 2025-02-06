<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('product_types')->insert([
           [
             'name'=> 'Room'
           ],

           [
            'name'=> 'Desk'
           ],

           [
            'name'=> 'Office'
           ],

           [
            'name'=> 'Resource'
           ]
        ]);
    }
}

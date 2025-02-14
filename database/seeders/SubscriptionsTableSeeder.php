<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubscriptionsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table("subscriptions")->insert([
            [
              'plan_id' => 1,
              'starts_at'=> now(),
              'ends_at'=> now()->addMonths(1),
              'status'=> 'active',
            ]
        ]);
    }
}

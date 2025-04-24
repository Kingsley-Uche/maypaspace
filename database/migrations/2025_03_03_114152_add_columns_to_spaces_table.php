<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{


    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->decimal('space_fee')->nullable()->after('floor_id');
            $table->unsignedBigInteger('min_space_discount_time')->nullable()->after('space_fee');
            $table->unsignedBigInteger('space_discount')->nullable()->after('min_space_discount_time');
            $table->unsignedBigInteger('space_category_id')->nullable()->after('space_discount');
            $table->foreign('space_category_id')
                  ->references('id')
                  ->on('categories')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
<<<<<<< HEAD
            // Remove the foreign key before dropping the column
            $table->dropForeign(['space_category_id']);

            $table->dropColumn([
                'space_price_hourly',
                'space_price_daily',
                'space_price_weekly',
                'space_price_monthly',
                'space_price_semi_annually',
                'space_price_annually',
                'space_category_id'
            ]);

            // Optionally, re-add the old column if needed:
            // $table->unsignedBigInteger('space_fee')->nullable();
=======
            // Drop foreign key before dropping column
            $table->dropForeign(['space_category_id']);

            $table->dropColumn([
                'space_fee',
                'min_space_discount_time',
                'space_discount'
            ]);
>>>>>>> e0a8eb61adbaf898691e47e6d122e5680a2a5296
        });
    }
};

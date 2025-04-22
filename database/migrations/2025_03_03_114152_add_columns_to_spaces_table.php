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
            $table->unsignedBigInteger('space_price_hourly')->after('floor_id')->nullable();
            $table->unsignedBigInteger('space_price_daily')->after('space_price_hourly')->nullable();
            $table->unsignedBigInteger('space_price_weekly')->after('space_price_daily')->nullable();
            $table->unsignedBigInteger('space_price_monthly')->after('space_price_weekly')->nullable();
            $table->unsignedBigInteger('space_price_semi_annually')->after('space_price_monthly')->nullable();
            $table->unsignedBigInteger('space_price_annually')->after('space_price_semi_annually')->nullable();
            $table->unsignedBigInteger('space_category_id')->after('space_price_annually')->nullable();
            $table->foreign('space_category_id')
                  ->references('id')
                  ->on('categories')
                  ->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
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
        });
    }
};

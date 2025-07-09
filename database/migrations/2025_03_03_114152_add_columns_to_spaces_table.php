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
          $table->decimal('space_fee', 15, 2)->nullable()->after('floor_id');
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
            // Drop foreign key before dropping column
            $table->dropForeign(['space_category_id']);

            $table->dropColumn([
                'space_fee',
                'min_space_discount_time',
                'space_discount'
            ]);
        });
    }
};

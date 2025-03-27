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
            $table->unsignedBigInteger('space_fee')->after('floor_id')->nullable();
            $table->unsignedBigInteger('space_category_id')->after('space_fee')->nullable();

            $table->foreign('space_category_id')->references('id')->on('categories')->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropForeign(['space_fee']);
            $table->dropForeign(['space_category_id']);

            $table->dropColumn(['space_category_id']);
        });
    }
};

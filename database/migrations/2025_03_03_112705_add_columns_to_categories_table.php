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
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->after('category')->nullable();
            $table->unsignedBigInteger('space_id')->after('location_id')->nullable();

            $table->foreign('location_id')->references('id')->on('locations')->onDelete('CASCADE');
            $table->foreign('space_id')->references('id')->on('spaces')->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropForeign(['space_id']);

            $table->dropColumn(['location_id']);
            $table->dropColumn(['space_id']);
        });
    }
};

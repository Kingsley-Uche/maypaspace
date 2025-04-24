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
        Schema::create('reserved_spots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('spot_id');
            $table->enum('day', ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']);
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->unsignedBigInteger('booked_spot_id');
            $table->dateTime('expiry_day')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('spot_id')->references('id')->on('spots')->onDelete('cascade');
            $table->foreign('booked_spot_id')->references('id')->on('book_spots')->onDelete('cascade');

            // Indexes
            $table->index('user_id');
            $table->index('spot_id');
            $table->index('booked_spot_id');
            $table->index('day');
            $table->index('start_time');
            $table->index('end_time');

            // Optional: Unique constraint to prevent double booking (if applicable)
            $table->unique(['user_id', 'spot_id', 'day', 'start_time', 'end_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reserved_spots');
    }
};

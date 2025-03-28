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
        Schema::create('book_spots', function (Blueprint $table) {
            $table->id();
            $table->dateTime('start_time'); 
            $table->dateTime('end_time'); 
            $table->unsignedBigInteger('booked_by_user')->nullable();
            $table->decimal('fee', 12, 2);
            $table->unsignedBigInteger('user_id'); 
            $table->unsignedBigInteger('spot_id');
            $table->unsignedBigInteger('booked_ref_id');
            
            // Set foreign key constraints with cascade delete
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('spot_id')->references('id')->on('spots')->onDelete('cascade'); 
            $table->foreign('booked_by_user')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('booked_ref_id')->references('id')->on('booked_refs')->onDelete('cascade');

            // Add soft deletes and timestamps
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('book_spots');
    }
};

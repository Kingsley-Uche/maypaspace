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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index(); // foreign user
            $table->string('invoice_ref')->index(); // index for lookup
            $table->decimal('amount', 10, 2);
            $table->unsignedBigInteger('book_spot_id')->index(); // foreign key to book_spots
            $table->unsignedBigInteger('booked_user_id')->index(); // foreign user
            $table->timestamps();

            // Optional foreign key constraints (uncomment if needed)
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('booked_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('book_spot_id')->references('id')->on('book_spots')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

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
        Schema::create('payment_listings', function (Blueprint $table) {
            $table->id();
            $table->string('payment_name');
            $table->decimal('fee', 20, 8);

            // foreign key to book_spots.id
            $table->foreignId('book_spot_id')
                  ->constrained('book_spots')
                  ->cascadeOnDelete();

            // foreign key to users.id
            $table->foreignId('tenant_id')
                  ->constrained('tenants')
                  ->cascadeOnDelete();

            // user who actually made the payment
            $table->foreignId('payment_by_user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            $table->boolean('payment_completed')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_listings');
    }
};

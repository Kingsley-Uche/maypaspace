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
            $table->unsignedBigInteger('spot_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('booked_by_user')->nullable();
            $table->unsignedBigInteger('booked_ref_id')->nullable();
            $table->enum('type', ['one-off', 'recurrent'])->default('one-off');
            $table->json('chosen_days')->nullable(); // Store days array for recurrent
            $table->unsignedInteger('number_weeks')->nullable();
            $table->unsignedInteger('number_months')->nullable();
            $table->dateTime('expiry_day')->nullable();
            $table->dateTime('start_time')->nullable();
            $table->decimal('fee', 12, 2)->default(0);
            $table->string('invoice_ref')->nullable()->index();
            $table->softDeletes();
            $table->timestamps();

            // Foreign keys
            $table->foreign('spot_id')->references('id')->on('spots')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('booked_by_user')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('booked_ref_id')->references('id')->on('booked_refs')->onDelete('cascade');
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


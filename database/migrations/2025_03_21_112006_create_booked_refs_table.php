<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $fillable = [
        'booked_ref',
        'booked_by_user',
        'user_id',
        'spot_id',
    ];
    public function up(): void
    {
        Schema::create('booked_refs', function (Blueprint $table) {
            $table->id();
            $table->string('booked_ref')->unique();
            $table->foreignId('booked_by_user')->constrained('users')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('spot_id')->constrained('spots')->onDelete('cascade');
            $table->string('payment_ref')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booked_refs');
    }
};

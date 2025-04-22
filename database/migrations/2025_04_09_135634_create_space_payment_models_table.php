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
        Schema::create('space_payment_models', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('spot_id')->constrained()->onDelete('cascade');

            $table->decimal('amount', 10, 2);

            $table->enum('payment_status', ['pending', 'completed', 'failed'])->default('pending')->index();
            $table->enum('payment_method', ['prepaid', 'postpaid'])->nullable()->index();
            $table->string('payment_ref')->nullable()->unique(); 
            $table->string('invoice_ref')->nullable()->index();   
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('space_payment_models');
    }
};

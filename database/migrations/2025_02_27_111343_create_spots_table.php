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
        Schema::create('spots', function (Blueprint $table) {
            $table->id();
            $table->enum('book_status', ['yes', 'no'])->default('no');
            $table->unsignedBigInteger('space_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('floor_id');
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();

            $table->foreign('space_id')->references('id')->on('spaces')->onDelete('CASCADE');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('CASCADE');
            $table->foreign('floor_id')->references('id')->on('floors')->onDelete('CASCADE');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spots');
    }
};

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
        Schema::create('spaces', function (Blueprint $table) {
            $table->id();
            $table->string('space_name');
            $table->unsignedBigInteger('space_number');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('floor_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->enum('deleted', ['yes', 'no'])->default('no');
            $table->unsignedBigInteger('deleted_by_user_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->foreign('location_id')->references('id')->on('locations')->onDelete('CASCADE');
            $table->foreign('floor_id')->references('id')->on('floors')->onDelete('CASCADE');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('CASCADE');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('SET NULL');
            $table->foreign('deleted_by_user_id')->references('id')->on('users')->onDelete('SET NULL');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spaces');
    }
};

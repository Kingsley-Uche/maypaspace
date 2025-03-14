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
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('company');
            $table->string('department')->nullable();
            $table->unsignedBigInteger('business_number')->nullable();
            $table->unsignedBigInteger('external_id')->nullable();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->enum('deleted', ['yes', 'no'])->default('no');
            $table->unsignedBigInteger('deleted_by_admin_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('CASCADE');
            $table->foreign('created_by_admin_id')->references('id')->on('admins')->onDelete('SET NULL');
            $table->foreign('deleted_by_admin_id')->references('id')->on('admins')->onDelete('SET NULL');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};

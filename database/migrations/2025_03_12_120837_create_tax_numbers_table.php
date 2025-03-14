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
        Schema::create('tax_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('value')->nullable();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('CASCADE');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_numbers');
    }
};

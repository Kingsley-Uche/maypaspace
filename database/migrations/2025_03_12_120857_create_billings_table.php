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
        Schema::create('billings', function (Blueprint $table) {
            $table->id();
            $table->string('country');
            $table->string('state');
            $table->string('street_address');
            $table->string('city');
            $table->unsignedBigInteger('postal_code');
            $table->text('additional_recipients');
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
        Schema::dropIfExists('billings');
    }
};

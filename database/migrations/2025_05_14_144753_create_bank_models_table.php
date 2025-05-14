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
        Schema::create('bank_models', function (Blueprint $table) {
           $table->id();
            $table->string('account_name');
            $table->string('account_number')->index(); // index for fast lookups
            $table->string('bank')->index(); // useful if filtering or grouping by bank
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_models');
    }
};

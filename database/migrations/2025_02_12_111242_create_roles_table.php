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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('role')->unique();
            $table->enum('create_tenant', ['yes', 'no'])->default('no');
            $table->enum('update_tenant', ['yes', 'no'])->default('no');
            $table->enum('delete_tenant', ['yes', 'no'])->default('no');
            $table->enum('view_tenant', ['yes', 'no'])->default('yes');
            $table->enum('view_tenant_income', ['yes', 'no'])->default('no');
            $table->enum('create_plan', ['yes', 'no'])->default('no');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};

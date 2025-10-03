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
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // charge name
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('space_id')->nullable();
            $table->boolean('is_fixed')->default(false);
            $table->decimal('value', 12, 2); // can be percentage or a fixed amount
            $table->timestamps();

            // Indexes
            $table->index(['tenant_id', 'space_id']);

            // Foreign keys
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('space_id')->references('id')->on('spaces')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};

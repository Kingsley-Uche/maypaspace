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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('total_seats');
            $table->string('images');
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('floor_id')->nullable(true);
            $table->unSignedBigInteger('product_type_id');
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
            $table->foreign('tenant_id')->references('id')->on( 'tenants')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on( 'locations')->onDelete('cascade');
            $table->foreign('floor_id')->references('id')->on( 'floors')->onDelete('cascade');
            $table->foreign('product_type_id')->references('id')->on( 'product_types')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

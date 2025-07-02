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
        Schema::create('category_images_models', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->index();
            $table->string('image_path');
            $table->timestamps();

            // Optional: add foreign key constraint if there's a `categories` table
            $table->foreign('category_id')
                  ->references('id')
                  ->on('categories')
                  ->onDelete('cascade'); // or 'set null', 'restrict', etc.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('category_images_models', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });

        Schema::dropIfExists('category_images_models');
    }
};

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
        Schema::create('sent_emails', function (Blueprint $table) {
            $table->id();
            $table->text('content'); 
            $table->string('subject');   
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();

            $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade');

            $table->foreign('tenant_id')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sent_emails');
    }
};

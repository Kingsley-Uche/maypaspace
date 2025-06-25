<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::create('otps', function (Blueprint $table) {
        $table->id();
        $table->string('email');
        $table->string('otp');
        $table->timestamp('expires_at');
        $table->boolean('is_used')->default(false);
        $table->unsignedBigInteger('tenant_id');
        $table->timestamps();

        $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otps');
    }
};

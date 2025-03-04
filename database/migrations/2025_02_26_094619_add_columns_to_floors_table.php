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
        Schema::table('floors', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->after('tenant_id');
            $table->unsignedBigInteger('created_by_user_id')->after('location_id')->nullable();
            $table->enum('deleted', ['yes', 'no'])->default('no')->after('created_by_user_id');
            $table->unsignedBigInteger('deleted_by_user_id')->after('deleted')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->foreign('location_id')->references('id')->on('locations')->onDelete('CASCADE');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('SET NULL');
            $table->foreign('deleted_by_user_id')->references('id')->on('users')->onDelete('SET NULL');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('floors', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropForeign(['created_by_user_id']);
            $table->dropForeign(['deleted_by_user_id']);

            $table->dropColumn(['location_id','created_by_user_id', 'deleted_by_user_id','deleted','deleted_at']);
        });
    }
};

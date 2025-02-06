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
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->after('password');
            $table->unsignedBigInteger('user_type_id')->after('password')->default(2);


            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('user_type_id')->references('id')->on('user_types')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {           
            // Drop the foreign key constraint
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['user_type_id']);

            // Drop the column
            $table->dropColumn('tenant_id');
            $table->dropColumn('user_type_id');
        });
    }
};

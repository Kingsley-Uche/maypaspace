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
        Schema::table('user_types', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_user_id')->default(null)->after('user_type')->nullable();
            $table->enum('create_admin', ['yes', 'no'])->default('no');
            $table->enum('update_admin', ['yes', 'no'])->default('no');
            $table->enum('delete_admin', ['yes', 'no'])->default('no');
            $table->enum('view_admin', ['yes', 'no'])->default('yes');
            $table->enum('create_user', ['yes', 'no'])->default('no');
            $table->enum('update_user', ['yes', 'no'])->default('no');
            $table->enum('delete_user', ['yes', 'no'])->default('no');
            $table->enum('view_user', ['yes', 'no'])->default('yes');
            $table->enum('create_location', ['yes', 'no'])->default('no');
            $table->enum('update_location', ['yes', 'no'])->default('no');
            $table->enum('delete_location', ['yes', 'no'])->default('no');
            $table->enum('view_location', ['yes', 'no'])->default('yes');
            $table->enum('create_floor', ['yes', 'no'])->default('no');
            $table->enum('update_floor', ['yes', 'no'])->default('no');
            $table->enum('delete_floor', ['yes', 'no'])->default('no');
            $table->enum('view_floor', ['yes', 'no'])->default('yes');
            $table->enum('create_space', ['yes', 'no'])->default('no');
            $table->enum('update_space', ['yes', 'no'])->default('no');
            $table->enum('delete_space', ['yes', 'no'])->default('no');
            $table->enum('view_space', ['yes', 'no'])->default('yes');
            $table->enum('create_booking', ['yes', 'no'])->default('no');
            $table->enum('update_booking', ['yes', 'no'])->default('no');
            $table->enum('delete_booking', ['yes', 'no'])->default('no');
            $table->enum('view_booking', ['yes', 'no'])->default('yes');

            $table->foreign('created_by_user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_types', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);

            $table->dropColumn('created_by_user_id');
            $table->dropColumn('create_admin');
            $table->dropColumn('update_admin');
            $table->dropColumn('delete_admin');
            $table->dropColumn('view_admin');
            $table->dropColumn('create_user');
            $table->dropColumn('update_user');
            $table->dropColumn('delete_user');
            $table->dropColumn('view_user');
            $table->dropColumn('create_location');
            $table->dropColumn('update_location');
            $table->dropColumn('delete_location');
            $table->dropColumn('view_location');
            $table->dropColumn('create_floor');
            $table->dropColumn('update_floor');
            $table->dropColumn('delete_floor');
            $table->dropColumn('view_floor');
            $table->dropColumn('create_space');
            $table->dropColumn('update_space');
            $table->dropColumn('delete_space');
            $table->dropColumn('view_space');
            $table->dropColumn('create_booking');
            $table->dropColumn('update_booking');
            $table->dropColumn('delete_booking');
            $table->dropColumn('view_booking');
        });
    }
};

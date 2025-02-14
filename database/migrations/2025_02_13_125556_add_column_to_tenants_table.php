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
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('company_countries')->after('slug'); 
            $table->string('company_no_location')->after('slug');
            $table->unsignedBigInteger('created_by_admin_id')->default(null)->after('slug')->nullable();
            $table->unsignedBigInteger('subscription_id')->default(null)->after('slug')->nullable();

            $table->foreign('created_by_admin_id')->references('id')->on('admins');
            $table->foreign('subscription_id')->references('id')->on('subscriptions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['created_by_admin_id']);
            $table->dropForeign(['subscription_id']);

             // Drop the column
            $table->dropColumn('company_countries');
            $table->dropColumn('company_no_location');
            $table->dropColumn('created_by_admin_id');
            $table->dropColumn('subscription_id');
        });
    }
};

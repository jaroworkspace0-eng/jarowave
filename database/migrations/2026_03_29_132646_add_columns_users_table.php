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
            $table->string('organisation_name')->nullable()->after('name'); 
            $table->string('organisation_type')->nullable()->after('organisation_name'); // 'watch' or 'estate'
            $table->string('plan')->nullable()->after('organisation_type'); // only for estate
            $table->string('billing_cycle')->nullable()->after('plan'); // estate only: monthly | annual
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('organisation_type');
            $table->dropColumn('organisation_name');
            $table->dropColumn('plan');
            $table->dropColumn('billing_cycle');
        });
    }
};

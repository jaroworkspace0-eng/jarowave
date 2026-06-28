<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Alter the enum to include 'delivery'
        DB::statement("ALTER TABLE visitor_codes MODIFY COLUMN visit_type ENUM('normal','ehailing','delivery') NOT NULL");

        // 2. Add delivery_company column
        Schema::table('visitor_codes', function (Blueprint $table) {
            $table->string('delivery_company', 100)->nullable()->after('vehicle_registration');
        });
    }

    public function down(): void
    {
        // Remove delivery_company column
        Schema::table('visitor_codes', function (Blueprint $table) {
            $table->dropColumn('delivery_company');
        });

        // Revert enum back to original values
        DB::statement("ALTER TABLE visitor_codes MODIFY COLUMN visit_type ENUM('normal','ehailing') NOT NULL");
    }
};
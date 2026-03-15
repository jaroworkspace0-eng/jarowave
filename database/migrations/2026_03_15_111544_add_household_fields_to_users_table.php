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
        Schema::table('users', function (Blueprint $column) {
            // Role & Identification
            // Address Details
            $column->string('address_line_1')->nullable()->after('role');
            $column->string('complex_name')->nullable()->after('address_line_1');
            $column->string('suburb')->nullable()->after('complex_name');
            
            // Security (Sensitive)
            $column->string('access_code')->nullable()->after('suburb');

            // Precise Location (GPS)
            // 10 total digits, 8 after the decimal point
            $column->decimal('latitude', 10, 8)->nullable()->after('access_code');
            $column->decimal('longitude', 11, 8)->nullable()->after('latitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $column) {
            $column->dropColumn([
                'occupation',
                'role',
                'address_line_1',
                'complex_name',
                'suburb',
                'access_code',
                'latitude',
                'longitude'
            ]);
        });
    }
};

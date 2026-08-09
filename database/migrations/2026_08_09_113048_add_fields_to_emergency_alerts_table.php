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
        Schema::table('emergency_alerts', function (Blueprint $table) {
            $table->enum('alert_location_source', ['gps', 'registered_address'])->nullable()->after('longitude');
            $table->boolean('is_estate')->default(false)->after('alert_location_source');
            $table->string('address_line_1')->nullable()->after('is_estate');
            $table->string('complex_name')->nullable()->after('address_line_1');
            $table->string('suburb')->nullable()->after('complex_name');
            $table->string('unit_number')->nullable()->after('suburb');
            $table->string('name')->nullable()->after('unit_number');
            $table->string('phone')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emergency_alerts', function (Blueprint $table) {
            $table->dropColumn([
                'alert_location_source',
                'is_estate',
                'address_line_1',
                'complex_name',
                'suburb',
                'unit_number',
                'name',
                'phone',
            ]);
        });
    }
};

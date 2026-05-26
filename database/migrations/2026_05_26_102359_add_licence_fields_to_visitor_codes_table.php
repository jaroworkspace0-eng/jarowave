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
        Schema::table('visitor_codes', function (Blueprint $table) {
            $table->text('licence_raw')->nullable()->after('departed_verified_by');
            $table->timestamp('licence_scanned_at')->nullable();
            $table->string('licence_id_number', 13)->nullable();
            $table->string('licence_name')->nullable();
            $table->string('licence_surname')->nullable();
            $table->date('licence_expiry')->nullable();
            $table->string('licence_codes', 20)->nullable();
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visitor_codes', function (Blueprint $table) {
            $table->dropColumn([
                'licence_raw',
                'licence_scanned_at',
                'licence_id_number',
                'licence_name',
                'licence_surname',
                'licence_expiry',
                'licence_codes',
            ]);
        });
    }
};

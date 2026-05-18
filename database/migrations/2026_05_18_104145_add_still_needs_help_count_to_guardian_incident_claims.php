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
        Schema::table('guardian_incident_claims', function (Blueprint $table) {
            $table->unsignedTinyInteger('still_needs_help_count')->default(0)->after('police_responded');
        });
    }

    public function down(): void
    {
        Schema::table('guardian_incident_claims', function (Blueprint $table) {
            $table->dropColumn('still_needs_help_count');
        });
    }
};

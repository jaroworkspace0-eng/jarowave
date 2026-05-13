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
            $table->string('outcome')->nullable()->after('resolution_note');
            $table->boolean('police_responded')->default(false)->after('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('guardian_incident_claims', function (Blueprint $table) {
            $table->dropColumn(['outcome', 'police_responded']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sos_incident_reports', function (Blueprint $table) {
            $table->foreignId('channel_id')->nullable()->after('reporter_user_id')->constrained()->nullOnDelete();
        });

        DB::statement('
            UPDATE sos_incident_reports sir
            JOIN emergency_alerts ea ON ea.id = sir.emergency_alert_id
            SET sir.channel_id = ea.channel_id
            WHERE sir.emergency_alert_id IS NOT NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sos_incident_reports', function (Blueprint $table) {
            $table->dropForeign(['channel_id']);
            $table->dropColumn('channel_id');
        });
    }
};

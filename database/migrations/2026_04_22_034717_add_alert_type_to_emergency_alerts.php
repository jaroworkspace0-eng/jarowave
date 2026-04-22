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
            $table->string('alert_type', 50)->default('sos')->after('client_id');
            $table->index('alert_type');
            $table->enum('cancel_pin_used', ['none', 'safe_cancel', 'duress'])->default('none')->after('alert_type')
                    ->comment('Indicates if the cancel PIN was used and its type (safe or duress)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emergency_alerts', function (Blueprint $table) {
            $table->dropIndex(['alert_type']);
            $table->dropColumn('alert_type');
            $table->dropColumn('cancel_pin_used');
        });
    }
};

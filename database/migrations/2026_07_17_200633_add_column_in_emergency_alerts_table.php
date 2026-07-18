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
            $table->decimal('trigger_lat', 10, 7)->nullable();
            $table->decimal('trigger_lng', 10, 7)->nullable();
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->timestamp('location_updated_at')->nullable();
            $table->timestamp('first_ack_at')->nullable(); // for SLA tracking
            $table->boolean('muted')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emergency_alerts', function (Blueprint $table) {
            $table->dropColumn([
                'channel_id',
                'trigger_lat',
                'trigger_lng',
                'last_lat',
                'last_lng',
                'location_updated_at',
                'first_ack_at',
                'muted'
            ]);
        });
    }
};

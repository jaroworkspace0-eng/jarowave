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
        Schema::create('alert_guardian_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emergency_alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained('users'); // or households table if guardians are households
            $table->timestamp('notified_at');
            $table->timestamp('responded_at')->nullable();
            $table->string('response_type')->nullable(); // acknowledged, calling, en_route
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_guardian_notifications');
    }
};

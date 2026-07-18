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
        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emergency_alert_id')->constrained()->cascadeOnDelete();
            $table->string('actor_type'); // household|guard|guardian|admin|system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event_type'); // created, guard_acknowledged, guard_en_route, guard_arrived,
                                            // guardian_notified, guardian_responded, admin_call_logged,
                                            // household_cancelled, resolved, escalated, muted
            $table->json('payload')->nullable(); // free-form: {outcome: 'no_answer'}, {distance_m: 120}, etc.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_events');
    }
};

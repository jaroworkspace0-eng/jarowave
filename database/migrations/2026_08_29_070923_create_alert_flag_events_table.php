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
        Schema::create('alert_flag_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // the household being flagged/cleared
            $table->foreignId('emergency_alert_id')->nullable()->constrained()->nullOnDelete(); // the alert that triggered this event, null for manual clears
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete(); // snapshot, in case household moves estates later
            $table->enum('event_type', ['flagged', 'cleared']);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete(); // null = system (auto-flag), set = human (clear)
            $table->string('actor_role')->nullable(); // snapshot: 'admin' | 'estate_billing' | 'gate_guard' | 'system'
            $table->unsignedInteger('alert_count_at_event')->nullable(); // manual alert count that triggered the flag
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_flag_events');
    }
};

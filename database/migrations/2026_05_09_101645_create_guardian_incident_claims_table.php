<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardian_incident_claims', function (Blueprint $table) {
            $table->id();

            $table->foreignId('emergency_alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('claimed_by_user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('status', [
                'claimed',
                'on_scene',
                'resolved',
                'escalated',
            ])->default('claimed');

            $table->timestamp('claimed_at');
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();

            $table->unique('emergency_alert_id');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_incident_claims');
    }
};
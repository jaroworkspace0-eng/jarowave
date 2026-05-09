<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardian_incident_responses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('emergency_alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('action', [
                'acknowledged',
                'on_my_way',
                'called_police',
                'safe_confirmed',
            ]);

            $table->text('note')->nullable();
            $table->timestamp('responded_at');

            $table->unique(['emergency_alert_id', 'user_id'], 'gir_alert_user_unique');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_incident_responses');
    }
};
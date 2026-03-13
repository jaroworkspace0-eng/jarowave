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
        Schema::create('emergency_resolutions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('emergency_alert_id')->references('id')
                  ->on('emergency_alerts') // Assuming your table is named emergency_alerts
                  ->onDelete('cascade');
            
            $table->unsignedBigInteger('responder_user_id')->nullable();
            $table->foreign('responder_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('resolution_time')->nullable();
            $table->string('arrival_latitude')->nullable();
            $table->string('arrival_longitude')->nullable();
            $table->string('start_latitude')->nullable();
            $table->string('start_longitude')->nullable();
            $table->string('response_duration')->nullable();
            $table->string('distance_traveled')->nullable();
            $table->enum('status', ['responding', 'on_site', 'resolved', 'false_alarm', 'transferred', 'cancelled'])->default('responding');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emergency_resolutions');
    }
};

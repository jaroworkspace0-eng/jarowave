<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sos_incident_reports', function (Blueprint $table) {
            $table->id();

            // The alert this report is about
            $table->unsignedBigInteger('emergency_alert_id')->nullable();
            $table->foreign('emergency_alert_id')->references('id')->on('emergency_alerts')->nullOnDelete();

            // Household whose SOS was activated
            $table->unsignedBigInteger('household_user_id');
            $table->foreign('household_user_id')->references('id')->on('users')->cascadeOnDelete();

            // Patroller who responded and filed the report
            $table->unsignedBigInteger('reporter_user_id');
            $table->foreign('reporter_user_id')->references('id')->on('users')->cascadeOnDelete();

            // Outcome
            $table->enum('outcome', ['legitimate', 'misuse']); // legit or misuse
            $table->enum('misuse_category', [
                'accidental',
                'prank',
                'domestic_dispute',
                'unfounded_fear',
                'repeated_false_alarm',
                'other',
            ])->nullable(); // only if outcome = misuse

            // Incident details
            $table->text('narrative');                          // written account of what happened
            $table->timestamp('arrived_at')->nullable();        // when patroller arrived
            $table->timestamp('departed_at')->nullable();       // when patroller left
            $table->boolean('injuries_reported')->default(false);
            $table->boolean('property_damage')->default(false);
            $table->text('additional_notes')->nullable();       // optional extra detail

            // Admin review
            $table->enum('status', [
                'pending',    // newly submitted, awaiting admin review
                'reviewed',   // admin has seen it
                'warned',     // warning email sent to household
                'blocked',    // conduct block applied
                'dismissed',  // admin dismissed as unfounded
            ])->default('pending');

            $table->text('admin_notes')->nullable();
            $table->unsignedBigInteger('actioned_by')->nullable(); // admin user id
            $table->foreign('actioned_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('actioned_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sos_incident_reports');
    }
};
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
        Schema::create('guardian_reports', function (Blueprint $table) {
            $table->id();
            $table->string('alert_id');
            $table->string('alert_type')->default('dv');
            $table->foreignId('reporting_household_id')->constrained('users')->cascadeOnDelete();
            $table->text('description');
            $table->boolean('seen_perpetrator')->default(false);
            $table->boolean('heard_disturbance')->default(false);
            $table->enum('severity', ['low', 'medium', 'high']);
            $table->timestamp('submitted_at')->useCurrent();

            // admin review
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->enum('review_status', [
                'pending',
                'reviewed',
                'escalated',
                'flagged',
            ])->default('pending');

            // if escalated, link to incident report
            $table->foreignId('incident_report_id')->nullable()->constrained('sos_incident_reports')->nullOnDelete();

            $table->timestamps();

            $table->index('alert_id');
            $table->index('review_status');
            $table->index('reporting_household_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_reports');
    }
};

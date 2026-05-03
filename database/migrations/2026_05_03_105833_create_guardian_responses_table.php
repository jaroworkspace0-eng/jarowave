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
        
        $table->unsignedBigInteger('reporting_household_id')->nullable();
        $table->foreign('reporting_household_id')->references('id')->on('users')->nullOnDelete();
        
        $table->text('description');
        $table->boolean('seen_perpetrator')->default(false);
        $table->boolean('heard_disturbance')->default(false);
        $table->enum('severity', ['low', 'medium', 'high']);
        $table->timestamp('submitted_at')->useCurrent();

        $table->unsignedBigInteger('reviewed_by')->nullable();
        $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        
        $table->timestamp('reviewed_at')->nullable();
        $table->text('review_notes')->nullable();
        $table->enum('review_status', ['pending', 'reviewed', 'escalated', 'flagged'])->default('pending');

        $table->unsignedBigInteger('incident_report_id')->nullable();
        $table->foreign('incident_report_id')->references('id')->on('sos_incident_reports')->nullOnDelete(); // ← fixed

        $table->timestamps();

        $table->index('alert_id');
        $table->index('review_status');
        $table->index('reporting_household_id');
    });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_responses');
    }
};

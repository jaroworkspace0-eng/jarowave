<?php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dv_recordings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('alert_id');
            $table->unsignedBigInteger('channel_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->string('file_path', 500)->nullable();   // absolute path on Node server disk
            $table->unsignedInteger('chunk_count')->default(0);
            $table->decimal('duration_secs', 8, 2)->nullable();
            $table->boolean('is_finalised')->default(false);
            $table->enum('cancel_pin_used', ['none', 'safe_cancel', 'duress'])
                  ->default('none')
                  ->comment('Which PIN was used when the victim cancelled this DV alert');
            $table->timestamps();
 
            $table->foreign('alert_id')
                  ->references('id')
                  ->on('emergency_alerts')
                  ->onDelete('cascade');
 
            $table->index('alert_id');
            $table->index('channel_id');
            $table->index('user_id');
        });
    }
 
    public function down(): void
    {
        Schema::dropIfExists('dv_recordings');
    }
};
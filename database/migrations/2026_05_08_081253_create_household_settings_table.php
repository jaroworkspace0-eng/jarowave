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
        Schema::create('household_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->boolean('auto_accept')->default(false);
            $table->boolean('sos_alerts')->default(true);
            $table->boolean('all_clear')->default(true);
            $table->boolean('appear_in_search')->default(true);
            $table->boolean('show_suburb')->default(true);
            $table->boolean('sound_vibrate')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('household_settings');
    }
};

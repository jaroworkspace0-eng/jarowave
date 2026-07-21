<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Move this file into database/migrations/ with a real timestamp prefix.
    public function up(): void
    {
        Schema::create('admin_alert_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope_type'); // 'channel' | 'household'
            $table->unsignedBigInteger('scope_id');
            $table->timestamps();

            // Exclusivity lock: one channel/household can only ever be
            // claimed once, by whichever admin holds the row — this is
            // what actually prevents two admins from both claiming it.
            $table->unique(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_alert_scopes');
    }
};
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
        Schema::create('estate_midcycle_optouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('channel_id')->constrained();
            $table->foreignId('channel_subscription_id')->constrained();
            $table->decimal('amount_owed', 10, 2); // their rate at time of opt-out, in case rates change later
            $table->timestamp('opted_out_at');
            $table->boolean('billed')->default(false); // so it doesn't get double-counted next cycle
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estate_midcycle_optouts');
    }
};

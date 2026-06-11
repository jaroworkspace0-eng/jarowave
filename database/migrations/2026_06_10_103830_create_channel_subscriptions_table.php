<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')
                  ->constrained()
                  ->cascadeOnDelete();
            $table->integer('household_count')->default(0);
            $table->decimal('amount_per_household', 10, 2)->default(80.00);
            $table->decimal('total_amount', 10, 2)->default(0.00);
            $table->enum('status', ['pending', 'active', 'overdue', 'cancelled'])
                  ->default('pending');
            $table->enum('billing_model', ['individual', 'bulk'])
                  ->default('individual');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_subscriptions');
    }
};
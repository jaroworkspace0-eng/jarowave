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
       Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->enum('plan', ['basic', 'standard', 'premium'])->nullable();
            $table->enum('status', ['trialing', 'active', 'past_due', 'cancelled'])->default('trialing');
            
            // Pricing snapshot
            $table->decimal('price', 10, 2)->nullable();                    // actual price charged e.g. 49900
            $table->decimal('original_price', 10, 2)->nullable();           // full price before discount e.g. 59900
            $table->decimal('discount_amount', 10, 2)->nullable();          // how much they saved e.g. 10000
            $table->tinyInteger('discount_percentage')->nullable();                    // e.g. 20 = 20% off
            $table->string('currency', 3)->default('ZAR');
            $table->enum('billing_cycle', ['monthly', 'annual'])->default('monthly');

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

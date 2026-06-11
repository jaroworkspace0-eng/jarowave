<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_subscription_id')
                  ->constrained()
                  ->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->integer('household_count');
            $table->decimal('amount_per_household', 10, 2)->default(80.00);
            $table->enum('payment_method', ['eft', 'payfast'])->nullable();
            $table->enum('status', ['pending', 'paid', 'failed'])->default('pending');
            $table->string('merchant_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_subscription_payments');
    }
};
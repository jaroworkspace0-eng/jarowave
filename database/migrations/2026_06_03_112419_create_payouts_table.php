<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();

            // The watch group / CPF client receiving the payout
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();

            // Human-friendly reference e.g. PAY-2026-06-001
            $table->string('reference')->nullable();

            // The earnings rows this payout covers
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            // How many households contributed to this payout
            $table->unsignedInteger('household_count')->default(0);

            // Amounts (stored in rands, 2 decimal places)
            $table->decimal('gross_amount', 10, 2)->default(0);   // sum of resident payments e.g. R80 × n
            $table->decimal('platform_fee', 10, 2)->default(0);   // Echo Link's 35% cut
            $table->decimal('net_amount', 10, 2)->default(0);     // what the client actually receives (65%)

            $table->enum('status', ['pending', 'paid', 'failed'])->default('pending');

            $table->timestamp('paid_at')->nullable();
            $table->string('transfer_reference')->nullable();  // EFT/bank ref when paid

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
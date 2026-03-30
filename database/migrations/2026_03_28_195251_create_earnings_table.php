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
        Schema::create('earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete(); // the watch group - client_id is the same as the user_id of the watch group
            $table->foreignId('subscription_payment_id')->constrained()->cascadeOnDelete(); // the resident payment that triggered this

            // The resident who paid
            $table->foreignId('resident_id')->constrained('users')->cascadeOnDelete(); // the resident who made the payment - user_id is the same as the user_id of the resident

            // Amounts
            $table->decimal('resident_amount', 10, 2);   // what resident paid e.g. 8000 (R80.00)
            $table->tinyInteger('commission_percentage');           // 65 — store it in case rate changes later
            $table->decimal('earned_amount', 10, 2);     // e.g. 5200 (R52.00)
            $table->decimal('platform_amount' ,10, 2);   // e.g. 2800 (R28.00) — Echo Link's cut

            // Payout tracking
            $table->enum('status', ['pending', 'approved', 'paid', 'withheld'])->default('pending');
            $table->timestamp('payout_at')->nullable();      // when Echo Link paid the watch group
            $table->string('payout_reference')->nullable();  // your bank transfer / EFT reference

            // Period this earning covers
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('earnings');
    }
};

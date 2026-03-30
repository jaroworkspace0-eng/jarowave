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
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            // Gateway
            $table->enum('gateway', ['payfast', 'ozow']); 
            $table->string('gateway_transaction_id')->nullable()->index(); // pf_payment_id / TransactionId
            $table->string('gateway_payment_reference')->nullable();       // m_payment_id (PayFast) / SiteCode (Ozow)
            $table->string('gateway_status')->nullable();                  // raw status string from gateway
            $table->string('merchant_reference')->nullable();                // merchant_reference (PayFast) / Reference (Ozow) - our internal reference for this payment, sent to gateway and returned in callback

            // Amount
            $table->decimal('amount', 10, 2);          // in cents e.g. 49900 = R499.00
            $table->decimal('amount_gross', 10, 2)->nullable();        // PayFast splits gross/fee/net
            $table->decimal('amount_fee', 10, 2)->nullable();
            $table->decimal('amount_net', 10, 2)->nullable();
            $table->string('currency', 3)->default('ZAR');

            // Payment detail
            $table->string('payment_method')->nullable(); // PayFast: payment_method

            // Payer info (from gateway callback — useful for reconciliation)
            $table->string('payer_name')->nullable();
            $table->string('payer_email')->nullable();

            // Status & audit
            $table->string('status')->default('pending'); // pending, completed, failed, refunded, etc. (normalized status for our app)
            $table->text('failure_reason')->nullable();                    // store gateway error message
            $table->json('gateway_payload')->nullable();                   // full raw ITN/webhook payload
            $table->string('signature')->nullable(); // e.g. 'valid', 'invalid', 'not_checked' — for PayFast ITN signature verification
            $table->string('ip_address')->nullable(); // IP address from which the payment was made (from gateway callback)

            // Billing period this payment covers
            $table->timestamp('billing_period_start')->nullable();
            $table->timestamp('billing_period_end')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable(); // if we support refunds later, we can track when this payment was refunded

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};

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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete(); // for easy querying, even though we can get client via subscription_payment -> subscription -> client
            $table->string('invoice_number')->unique(); // ECL-INV-2026-00001
            $table->enum('status', ['draft', 'issued', 'paid', 'void'])->default('issued');
            $table->unsignedBigInteger('subtotal');       // in cents before discount
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('total');          // what was actually charged
            $table->string('currency', 3)->default('ZAR');
            $table->text('notes')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('sent_at')->nullable();     // when email was sent
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

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
        Schema::table('channel_subscription_payments', function (Blueprint $table) {
            // 1. Add the missing columns safely after merchant_reference
            $table->string('proof_of_payment')->nullable()->after('merchant_reference');
            $table->text('notes')->nullable()->after('proof_of_payment');
            $table->string('ip_address', 45)->nullable()->after('notes');

            // 2. Change the enum status column to include 'pending_review'
            $table->enum('status', ['pending', 'pending_review', 'paid', 'failed'])
                  ->default('pending')
                  ->change();
        });
    }

    public function down(): void
    {
        Schema::table('channel_subscription_payments', function (Blueprint $table) {
            $table->dropColumn(['proof_of_payment', 'notes', 'ip_address']);
            
            $table->enum('status', ['pending', 'paid', 'failed'])
                  ->default('pending')
                  ->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('channel_subscription_id')
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete()
                  ->after('id');
            $table->foreignId('channel_subscription_payment_id')
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete()
                  ->after('channel_subscription_id');
            $table->boolean('paid_via_estate')->default(false)
                  ->after('channel_subscription_payment_id');
            $table->enum('invoice_type', ['individual', 'estate_bulk', 'estate_household'])
                  ->default('individual')
                  ->after('paid_via_estate');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['channel_subscription_id']);
            $table->dropForeign(['channel_subscription_payment_id']);
            $table->dropColumn([
                'channel_subscription_id',
                'channel_subscription_payment_id',
                'paid_via_estate',
                'invoice_type'
            ]);
        });
    }
};
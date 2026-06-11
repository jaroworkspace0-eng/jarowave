<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('channel_subscription_id')
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete()
                  ->after('user_id');
            $table->enum('cancellation_reason', [
                      'user',
                      'estate_optin',
                      'admin',
                      'payment_failure'
                  ])
                  ->nullable()
                  ->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['channel_subscription_id']);
            $table->dropColumn(['channel_subscription_id', 'cancellation_reason']);
        });
    }
};
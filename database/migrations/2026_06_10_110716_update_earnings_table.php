<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('earnings', function (Blueprint $table) {
            $table->foreignId('channel_subscription_payment_id')
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete()
                  ->after('subscription_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('earnings', function (Blueprint $table) {
            $table->dropForeign(['channel_subscription_payment_id']);
            $table->dropColumn('channel_subscription_payment_id');
        });
    }
};
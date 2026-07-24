<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('linked_account_count')->default(0)->after('household_count');
            $table->decimal('amount_per_linked_account', 8, 2)->nullable()->after('amount_per_household');
        });

        Schema::table('channel_subscription_payments', function (Blueprint $table) {
            $table->unsignedInteger('linked_account_count')->default(0)->after('household_count');
            $table->decimal('amount_per_linked_account', 8, 2)->nullable()->after('amount_per_household');
        });
    }

    public function down(): void
    {
        Schema::table('channel_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['linked_account_count', 'amount_per_linked_account']);
        });

        Schema::table('channel_subscription_payments', function (Blueprint $table) {
            $table->dropColumn(['linked_account_count', 'amount_per_linked_account']);
        });
    }
};
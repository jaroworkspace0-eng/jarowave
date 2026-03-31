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
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('merchant_reference')->nullable()->after('gateway');
            $table->string('payfast_token')->nullable()->after('merchant_reference');
            $table->string('gateway_status')->nullable()->after('payfast_token');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['merchant_reference', 'payfast_token', 'gateway_status']);
        });
    }
};

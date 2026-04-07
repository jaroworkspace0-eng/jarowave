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
        Schema::table('users', function (Blueprint $table) {
            $table->string('subscription_status')->default('active')->after('billing_cycle'); // 'active', 'past_due', 'suspended'
            $table->timestamp('payment_failed_at')->nullable()->after('subscription_status');
            $table->timestamp('sos_suspended_at')->nullable()->after('payment_failed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('subscription_status');
            $table->dropColumn('payment_failed_at');
            $table->dropColumn('sos_suspended_at');
        });
    }
};

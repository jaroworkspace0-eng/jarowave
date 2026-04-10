<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE subscription_payments MODIFY COLUMN gateway ENUM('payfast', 'ozow', 'manual_eft') NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_payments_gateway', function (Blueprint $table) {
            DB::statement("ALTER TABLE subscription_payments MODIFY COLUMN gateway ENUM('payfast', 'ozow') NULL");
        });
    }
};

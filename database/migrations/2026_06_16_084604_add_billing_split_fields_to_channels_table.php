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
        Schema::table('channels', function (Blueprint $table) {
            $table->decimal('guard_fixed_amount', 8, 2)->default(0)->after('amount_per_household');
            $table->decimal('security_pool', 8, 2)->nullable()->after('guard_fixed_amount');
            $table->decimal('security_percentage', 5, 2)->nullable()->after('security_pool');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['guard_fixed_amount', 'security_pool', 'security_percentage']);
        });
    }
};

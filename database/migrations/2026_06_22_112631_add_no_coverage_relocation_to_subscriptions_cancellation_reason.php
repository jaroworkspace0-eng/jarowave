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
        DB::statement("ALTER TABLE subscriptions MODIFY COLUMN cancellation_reason ENUM(
            'user',
            'estate_optin',
            'admin',
            'payment_failure',
            'no_coverage_relocation'
        )");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE subscriptions MODIFY COLUMN cancellation_reason ENUM(
            'user',
            'estate_optin',
            'admin',
            'payment_failure',
        )");
    }
};
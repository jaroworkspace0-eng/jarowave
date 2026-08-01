<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE channel_subscription_payments MODIFY status ENUM('pending','pending_review','paid','failed','rejected','abandoned') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        // Any existing 'abandoned' rows must be reassigned before the enum
        // can drop the value, or MySQL will truncate them to '' and error.
        DB::statement("UPDATE channel_subscription_payments SET status = 'failed' WHERE status = 'abandoned'");
        DB::statement("ALTER TABLE channel_subscription_payments MODIFY status ENUM('pending','pending_review','paid','failed','rejected') NOT NULL DEFAULT 'pending'");
    }
};
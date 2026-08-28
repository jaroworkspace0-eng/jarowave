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
            $table->boolean('alert_flagged_for_review')->default(false)->after('is_active');
            $table->timestamp('alert_flagged_at')->nullable()->after('alert_flagged_for_review');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['alert_flagged_for_review', 'alert_flagged_at']);
        });
    }
};

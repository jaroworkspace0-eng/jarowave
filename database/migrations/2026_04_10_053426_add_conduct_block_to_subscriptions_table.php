<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('conduct_blocked_at')->nullable()->after('sos_suspended_at');
            $table->string('conduct_block_reason')->nullable()->after('conduct_blocked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('conduct_blocked_at');
            $table->dropColumn('conduct_block_reason');
        });
    }
};

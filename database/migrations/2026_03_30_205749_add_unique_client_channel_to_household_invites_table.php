<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('household_invites', function (Blueprint $table) {
            // Drop old single unique on client_id if it exists
            // then add unique per client+channel combination
            $table->unique(['client_id', 'channel_id'], 'household_invites_client_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::table('household_invites', function (Blueprint $table) {
            $table->dropUnique('household_invites_client_channel_unique');
        });
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->longText('target_channel_ids')->nullable()->after('target_employee_ids');
        });

        // Extend the target enum to include 'channel'
        DB::statement("ALTER TABLE announcements MODIFY COLUMN target ENUM('all','client','users','household','patroller','field_unit','channel') NOT NULL DEFAULT 'all'");
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('target_channel_ids');
        });

        DB::statement("ALTER TABLE announcements MODIFY COLUMN target ENUM('all','client','users','household','patroller','field_unit') NOT NULL DEFAULT 'all'");
    }
};
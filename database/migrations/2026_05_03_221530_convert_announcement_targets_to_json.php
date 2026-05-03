<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            // Drop the single-value foreign key columns
            // and replace with JSON arrays to support multi-select
            $table->dropForeign(['target_client_id']);
            $table->dropForeign(['target_employee_id']);

            $table->dropColumn(['target_client_id', 'target_employee_id']);
        });

        Schema::table('announcements', function (Blueprint $table) {
            // JSON arrays — store multiple selected IDs
            // e.g. target_client_ids: [1, 3, 7]
            // e.g. target_employee_ids: [44, 108]
            $table->json('target_client_ids')->nullable()->after('target');
            $table->json('target_employee_ids')->nullable()->after('target_client_ids');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['target_client_ids', 'target_employee_ids']);
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->unsignedBigInteger('target_client_id')->nullable()->after('target');
            $table->unsignedBigInteger('target_employee_id')->nullable()->after('target_client_id');

            $table->foreign('target_client_id')->references('id')->on('clients')->onDelete('set null');
            $table->foreign('target_employee_id')->references('id')->on('employees')->onDelete('set null');
        });
    }
};
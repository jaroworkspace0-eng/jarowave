<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Enum changes must be raw statements — they cannot go inside Schema::table()
        DB::statement("ALTER TABLE announcements MODIFY COLUMN type ENUM('general','urgent','update','policy','payment','update_app') NOT NULL DEFAULT 'general'");
        DB::statement("ALTER TABLE announcements MODIFY COLUMN target ENUM('all','client','users','household') NOT NULL DEFAULT 'all'");

        Schema::table('announcements', function (Blueprint $table) {
            $table->string('payment_subtype')->nullable()->after('type');
            $table->unsignedBigInteger('target_employee_id')->nullable()->after('target_client_id');
            $table->string('app_version')->nullable()->after('target_employee_id');
            $table->string('playstore_url')->nullable()->after('app_version');
            $table->unsignedBigInteger('client_id')->nullable()->after('sent_by');

            $table->foreign('target_employee_id')->references('id')->on('employees')->onDelete('set null');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropForeign(['target_employee_id']);
            $table->dropForeign(['client_id']);
            $table->dropColumn(['payment_subtype', 'target_employee_id', 'app_version', 'playstore_url', 'client_id']);
        });

        DB::statement("ALTER TABLE announcements MODIFY COLUMN type ENUM('general','urgent','update','policy') NOT NULL DEFAULT 'general'");
        DB::statement("ALTER TABLE announcements MODIFY COLUMN target ENUM('all','client','users') NOT NULL DEFAULT 'all'");
    }
};
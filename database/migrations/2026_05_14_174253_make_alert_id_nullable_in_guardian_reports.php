<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guardian_reports', function (Blueprint $table) {
            $table->string('alert_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('guardian_reports', function (Blueprint $table) {
            $table->string('alert_id')->nullable(false)->change();
        });
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkpoints', function (Blueprint $table) {
            $table->foreignId('channel_id')->nullable()->after('client_id')
                ->constrained('channels')->nullOnDelete();
        });

        Schema::table('checkpoint_scans', function (Blueprint $table) {
            $table->foreignId('channel_id')->nullable()->after('checkpoint_id')
                ->constrained('channels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checkpoints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_id');
        });

        Schema::table('checkpoint_scans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_id');
        });
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->enum('channel_type', ['residential', 'operational'])
                  ->default('residential')
                  ->after('category');
            $table->enum('billing_model', ['individual', 'bulk'])
                  ->default('individual')
                  ->after('channel_type');
            $table->dropColumn('type');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['channel_type', 'billing_model']);
            $table->string('type')->nullable()->after('category');
        });
    }
};
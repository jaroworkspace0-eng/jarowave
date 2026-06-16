<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('earnings', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('client_id')->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('client_id')->nullable()->change();
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('client_id')->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('client_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('earnings', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
        });
    }
};
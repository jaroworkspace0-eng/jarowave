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
        Schema::table('tenant_address_histories', function (Blueprint $table) {
            $table->boolean('is_inherited')->default(false)->after('user_id');
            $table->foreignId('source_user_id')->nullable()->after('is_inherited')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_address_histories', function (Blueprint $table) {
            $table->dropColumn([
                'is_inherited',
                'source_user_id'
            ]);
        });
    }
};

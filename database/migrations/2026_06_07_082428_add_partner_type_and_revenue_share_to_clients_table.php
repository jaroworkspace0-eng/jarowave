<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->enum('partner_type', ['outsourced', 'existing_clients'])
                  ->default('outsourced')
                  ->after('id');
            $table->decimal('revenue_share_percentage', 5, 2)
                  ->default(30.00)
                  ->after('partner_type');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['partner_type', 'revenue_share_percentage']);
        });
    }
};
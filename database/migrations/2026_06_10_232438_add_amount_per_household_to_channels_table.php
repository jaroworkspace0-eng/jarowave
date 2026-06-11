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
    Schema::table('channels', function (Blueprint $table) {
        $table->decimal('amount_per_household', 8, 2)->default(80)->after('billing_model');
    });
}

public function down(): void
{
    Schema::table('channels', function (Blueprint $table) {
        $table->dropColumn('amount_per_household');
    });
}
};

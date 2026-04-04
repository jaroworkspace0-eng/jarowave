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
        Schema::table('emergency_resolutions', function (Blueprint $table) {
            $table->string('confirmation_status', 50)
                   ->default('pending')
                   ->change();

            $table->string('status', 50)
            ->default('responding')
            ->change();
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emergency_resolutions', function (Blueprint $table) {
            $table->enum('confirmation_status', ['pending', 'confirmed', 'auto_confirmed', 'denied'])
                   ->default('pending')
                   ->change();

            $table->enum('status', ['responding','on_site','resolved','false_alarm','transferred','cancelled'])
                   ->default('pending')
                   ->change();
        });
    }
};

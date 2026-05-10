<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE guardian_incident_responses MODIFY COLUMN action ENUM('acknowledged', 'on_my_way', 'called_police', 'safe_confirmed', 'on_scene')");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE guardian_incident_responses MODIFY COLUMN action ENUM('acknowledged', 'on_my_way', 'called_police', 'safe_confirmed')");
    }
};

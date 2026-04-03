<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('emergency_resolutions', function (Blueprint $table) {
            $table->enum('confirmation_status', ['pending', 'confirmed', 'auto_confirmed', 'denied'])
                ->default('pending')
                ->after('status');
            $table->timestamp('confirmed_at')->nullable()->after('confirmation_status');
            $table->string('confirmed_by')->nullable()->after('confirmed_at'); // 'victim', 'timeout', 'forced'
            $table->text('victim_response')->nullable()->after('confirmed_by'); // optional victim note
        });
    }

    public function down()
    {
        Schema::table('emergency_resolutions', function (Blueprint $table) {
            $table->dropColumn(['confirmation_status', 'confirmed_at', 'confirmed_by', 'victim_response']);
        });
    }
};

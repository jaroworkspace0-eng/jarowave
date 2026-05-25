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
        Schema::create('visitor_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->enum('visit_type', ['normal', 'ehailing'])->default('normal');
            $table->string('visitor_name');
            $table->string('visitor_phone')->nullable();
            $table->string('visitor_id_number')->nullable();
            $table->string('vehicle_registration')->nullable();
            $table->text('notes')->nullable();
            $table->string('code', 6)->unique();
            $table->uuid('qr_token')->unique();
            $table->enum('status', ['pending', 'arrived', 'departed', 'expired', 'revoked'])->default('pending');
            $table->timestamp('expected_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('day_expires_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->foreignId('arrived_verified_by')->nullable()->constrained('users');
            $table->foreignId('departed_verified_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visitor_codes');
    }
};

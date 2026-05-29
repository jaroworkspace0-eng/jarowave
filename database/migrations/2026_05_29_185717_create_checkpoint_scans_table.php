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
        Schema::create('checkpoint_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkpoint_id')->constrained('checkpoints')->onDelete('cascade');
            $table->foreignId('guard_id')->constrained('users')->onDelete('cascade'); // the guard who scanned
            $table->string('note')->nullable(); // optional observation
            $table->timestamp('scanned_at'); // when they scanned
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkpoint_scans');
    }
};

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
        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'processing', 'deleted', 'cancelled'])->default('pending');
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('scheduled_deletion_at')->nullable(); // 30 days from request
            $table->timestamp('processed_at')->nullable();          // when actually deleted
            $table->foreignId('processed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('processed_by_type', ['system', 'admin'])->nullable(); // ← add this
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_deletion_requests');
    }
};

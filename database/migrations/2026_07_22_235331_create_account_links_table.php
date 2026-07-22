<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('primary_account_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('linked_account_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'active', 'rejected'])->default('pending');
            $table->boolean('escalated')->default(false);
            $table->timestamp('escalated_at')->nullable();
            $table->string('approved_by_type')->nullable(); // 'estate_admin' | 'echo_link_admin'
            $table->unsignedBigInteger('approved_by_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            // A given linked_account can only have ONE non-rejected link
            // at a time (pending or active) — enforced in app logic below
            // since partial unique indexes aren't portable across DB
            // engines; this index just speeds up that lookup.
            $table->index(['linked_account_id', 'status']);
            $table->index(['primary_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_links');
    }
};
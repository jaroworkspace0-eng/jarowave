<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_details', function (Blueprint $table) {
            $table->id();

            // One set of bank details per client (watch group)
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();

            $table->string('bank_name');
            $table->string('account_holder');
            $table->string('account_number');
            $table->string('account_type');   // Cheque | Savings | Transmission
            $table->string('branch_code');

            $table->timestamps();

            $table->unique('client_id');  // one record per client, update in place
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_details');
    }
};
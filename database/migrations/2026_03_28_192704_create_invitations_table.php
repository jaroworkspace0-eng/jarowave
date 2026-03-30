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
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete(); // the client sending the invite - client_id is the same as the user_id of the inviter
            $table->string('email'); // the email of the invitee
            $table->string('token', 64)->unique(); // unique token for accepting the invite
            $table->timestamp('accepted_at')->nullable(); // when the invite was accepted
            $table->timestamp('expires_at')->nullable(); // when the invite expires (e.g. 7 days after creation)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};

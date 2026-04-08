<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User    $user,
        public bool    $adminCreated = false, // true = created by super admin, false = self-registered
        public ?string $tempPassword = null,  // only for admin-created accounts
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Echo Link - Your Account is Ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.client.welcome',
        );
    }
}
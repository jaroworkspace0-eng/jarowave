<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountLinkedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $linkedAccount,
        public User $primary,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You\'re Now Linked to ' . $this->primary->name . '\'s Household',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.account-links.linked-confirmation',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
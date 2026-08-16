<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountUnlinkedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $linkedAccount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Echo Link Account Has Been Unlinked',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.account-links.unlinked-notice',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
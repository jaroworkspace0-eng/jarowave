<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountLinkApprovedPrimaryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $primary,
        public User $linkedAccount,
        public bool $isEstateBilled,
        public ?float $newMonthlyAmount, // null when isEstateBilled, or when nothing changed
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Link Request Approved - ' . $this->linkedAccount->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.account-links.approved-primary',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
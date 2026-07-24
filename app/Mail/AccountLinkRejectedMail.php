<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountLinkRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $primary,
        public User $linkedAccount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Link Request Declined - ' . $this->linkedAccount->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.account-links.rejected-primary',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
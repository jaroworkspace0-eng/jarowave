<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConductBlockMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'SOS Access Suspended',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.conduct-block',  // ← markdown: not view:
            with: [
                'userName' => $this->userName,
                'reason'   => $this->reason,
            ],
        );
    }
}
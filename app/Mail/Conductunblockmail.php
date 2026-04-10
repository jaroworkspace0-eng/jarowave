<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConductUnblockMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'SOS Access Restored',
        );
    }


    public function content(): Content
    {
        return new Content(
            markdown: 'emails.conduct-unblock',  // ← markdown: not view:
            with: [
                'userName' => $this->userName,
            ],
        );
    }
}
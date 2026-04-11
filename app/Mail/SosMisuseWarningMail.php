<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SosMisuseWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public int    $reportCount,
        public string $narrative,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'SOS Misuse Warning',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.sos-misuse-warning',
            with: [
                'userName'    => $this->userName,
                'reportCount' => $this->reportCount,
                'narrative'   => $this->narrative,
            ],
        );
    }
}
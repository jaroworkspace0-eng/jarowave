<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;

class TrialCardFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $userName;
    public Carbon $trialEndsAt;

    public function __construct(string $userName, Carbon $trialEndsAt)
    {
        $this->userName    = $userName;
        $this->trialEndsAt = $trialEndsAt;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'We couldn\'t verify your card — your trial is unaffected',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.payment.trial-card-failed',
            with: [
                'trialEndsAtFormatted' => $this->trialEndsAt->format('d M Y'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;

class AlertAbuseFlagMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $userName;
    public string $unitNumber;
    public int $alertCount;
    public Carbon $flaggedAt;
    public string $reviewUrl;

    public function __construct(string $userName, string $unitNumber, int $alertCount, Carbon $flaggedAt, string $reviewUrl)
    {
        $this->userName   = $userName;
        $this->unitNumber = $unitNumber;
        $this->alertCount = $alertCount;
        $this->flaggedAt  = $flaggedAt;
        $this->reviewUrl  = $reviewUrl;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Household flagged for alert review — {$this->userName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.alerts.abuse-flag',
            with: [
                'flaggedAtFormatted' => $this->flaggedAt->format('d M Y, H:i'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
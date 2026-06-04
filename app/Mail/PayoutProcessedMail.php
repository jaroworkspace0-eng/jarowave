<?php

namespace App\Mail;

use App\Models\Payout;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PayoutProcessedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User   $client,
        public Payout $payout,
        public int    $earningCount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Echo Link payout of ' . 'R' . number_format($this->payout->net_amount, 2) . ' has been processed',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payout-processed');
    }

    public function attachments(): array
    {
        return [];
    }
}
<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NoBankDetailsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $client) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Action required: Add your bank details to receive your Echo Link payout',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.no-bank-details');
    }

    public function attachments(): array
    {
        return [];
    }
}
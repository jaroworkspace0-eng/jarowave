<?php

namespace App\Mail;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ChannelRateChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Channel $channel,
        public User $billingContact,
        public float $oldAmountPerHousehold,
        public float $newAmountPerHousehold,
        public int $householdCount,
        public float $oldTotalAmount,
        public float $newTotalAmount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Billing Rate Update - ' . $this->channel->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.channels.rate-changed',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
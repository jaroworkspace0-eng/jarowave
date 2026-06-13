<?php

namespace App\Mail;

use App\Models\ChannelSubscription;
use App\Models\ChannelSubscriptionPayment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EstatePaymentRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public ChannelSubscription $channelSubscription,
        public ChannelSubscriptionPayment $payment,
        public string $reason
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'EFT Payment Rejected - Action Required');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.estate-payment-rejected');
    }
}
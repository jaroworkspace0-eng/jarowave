<?php

namespace App\Mail;

use App\Models\Channel;
use App\Models\ChannelSubscription;
use App\Models\ChannelSubscriptionPayment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EstatePaymentApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public ChannelSubscription $channelSubscription,
        public ChannelSubscriptionPayment $payment
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'EFT Payment Approved - Households Activated');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.estate-payment-approved');
    }
}
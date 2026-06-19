<?php

namespace App\Mail;

use App\Models\Channel;
use App\Models\ChannelSubscription;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EstateSuspendedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User                $billingContact,
        public Channel              $channel,
        public ChannelSubscription $channelSubscription,
    ) {}

    public function envelope(): \Illuminate\Mail\Mailables\Envelope
    {
        return new \Illuminate\Mail\Mailables\Envelope(
            subject: "Action required: {$this->channel->name} estate protection has been paused",
        );
    }

    public function content(): \Illuminate\Mail\Mailables\Content
    {
        return new \Illuminate\Mail\Mailables\Content(
            markdown: 'emails.estate-suspended',
        );
    }
}
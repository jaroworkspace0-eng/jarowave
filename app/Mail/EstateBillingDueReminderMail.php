<?php

namespace App\Mail;

use App\Models\Channel;
use App\Models\ChannelSubscription;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EstateBillingDueReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User                $billingContact,
        public Channel              $channel,
        public ChannelSubscription $channelSubscription,
        public int                 $daysUntilDue,
    ) {}

    public function envelope(): \Illuminate\Mail\Mailables\Envelope
    {
        $dayWord = $this->daysUntilDue === 1 ? 'day' : 'days';

        return new \Illuminate\Mail\Mailables\Envelope(
            subject: "{$this->channel->name}: payment due in {$this->daysUntilDue} {$dayWord}",
        );
    }

    public function content(): \Illuminate\Mail\Mailables\Content
    {
        return new \Illuminate\Mail\Mailables\Content(
            markdown: 'emails.estate-billing-due-reminder',
        );
    }
}
<?php

namespace App\Mail;

use App\Models\Channel;
use App\Models\ChannelSubscription;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EstateBillingOverdueReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User                $billingContact,
        public Channel              $channel,
        public ChannelSubscription $channelSubscription,
        public int                 $daysOverdue,
    ) {}

    public function envelope(): \Illuminate\Mail\Mailables\Envelope
    {
        $subject = $this->daysOverdue >= 7
            ? "Final notice: {$this->channel->name} estate billing is overdue"
            : "Action required: {$this->channel->name} estate billing is overdue";

        return new \Illuminate\Mail\Mailables\Envelope(
            subject: $subject,
        );
    }

    public function content(): \Illuminate\Mail\Mailables\Content
    {
        return new \Illuminate\Mail\Mailables\Content(
            markdown: 'emails.estate-billing-overdue-reminder',
        );
    }
}
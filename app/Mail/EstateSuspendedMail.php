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

    /**
     * True if this estate never completed its first payment (was still
     * 'pending' when suspended) — as opposed to having been active and
     * then lapsing on a later renewal. Distinguishes "never got started"
     * from "protection has been paused" in the subject/copy, since the
     * latter implies the estate previously had working protection.
     */
    public bool $neverActivated;

    public function __construct(
        public User                $billingContact,
        public Channel              $channel,
        public ChannelSubscription $channelSubscription,
    ) {
        $this->neverActivated = is_null($channelSubscription->paid_at);
    }

    public function envelope(): \Illuminate\Mail\Mailables\Envelope
    {
        $subject = $this->neverActivated
            ? "Action required: {$this->channel->name} estate setup was not completed"
            : "Action required: {$this->channel->name} estate protection has been paused";

        return new \Illuminate\Mail\Mailables\Envelope(
            subject: $subject,
        );
    }

    public function content(): \Illuminate\Mail\Mailables\Content
    {
        return new \Illuminate\Mail\Mailables\Content(
            markdown: 'emails.estate-suspended',
        );
    }
}
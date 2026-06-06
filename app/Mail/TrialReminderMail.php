<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Content;

class TrialReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User         $user,
        public Subscription $subscription,
        public int          $daysLeft,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your Echo Link trial ends in {$this->daysLeft} " . ($this->daysLeft === 1 ? 'day' : 'days'),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.trial-reminder');
    }
}
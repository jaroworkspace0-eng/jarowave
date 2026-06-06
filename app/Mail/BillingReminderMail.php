<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Content;

class BillingReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User         $user,
        public Subscription $subscription,
        public int          $daysLeft,
        public bool         $failedPayment = false,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->failedPayment
            ? 'Action required: Update your Echo Link payment details'
            : "Upcoming Echo Link payment in {$this->daysLeft} " . ($this->daysLeft === 1 ? 'day' : 'days');

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.billing-reminder');
    }
}
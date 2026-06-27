<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HouseholdWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User   $user,
        public string $organisationName,
        public string $gateway,           // 'payfast' | 'ozow' | 'none'
        public bool   $adminAdded = false, // true = added by admin, false = self-registered
        public ?string $tempPassword = null, // only set for admin-added households
        public ?string $amountPerHousehold,
        public ?string $channelName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Echo Link - ' . ($this->channelName ?? $this->organisationName),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.household.welcome',
            with: [
                'amount_per_household' => $this->amountPerHousehold 
                ? number_format($this->amountPerHousehold, 0) 
                : 'R80',
                'channel_name' => $this->channelName
            ]
        );
    }
}
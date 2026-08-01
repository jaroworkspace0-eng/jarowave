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
        public string $gateway,
        public bool   $adminAdded = false,
        public ?string $tempPassword = null,
        public ?string $amountPerHousehold = null,
        public ?string $channelName = null,
        public bool   $estateBilled = false,   // new
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
                'amount_per_household' => $this->amountPerHousehold ? number_format($this->amountPerHousehold, 0) : 'R80',
                'channel_name' => $this->channelName,
                'estate_billed' => $this->estateBilled,   // new
            ],
        );
    }
}
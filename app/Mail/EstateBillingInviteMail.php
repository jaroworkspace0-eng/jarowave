<?php

namespace App\Mail;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Password;

class EstateBillingInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $resetLink;

    public function __construct(public User $user, public Channel $channel)
    {
        $this->resetLink = url('/reset-password/' . Password::createToken($user) . '?email=' . urlencode($user->email));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Echo Link Estate Billing Account');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.estate-billing-invite');
    }
}
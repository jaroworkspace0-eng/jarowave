<?php
namespace App\Mail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class HouseholdNoCoverageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function build(): self
    {
        return $this->subject('Echo Link Access Paused — No Coverage at Your Address')
            ->markdown('emails.household-no-coverage')
            ->with(['user' => $this->user]);
    }
}
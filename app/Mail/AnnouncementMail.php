<?php

namespace App\Mail;

use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AnnouncementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Announcement $announcement,
        public string $recipientName = 'there',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine(),
        );
    }

    public function content(): Content
    {
        $badge = $this->badgeColors();

        return new Content(
            markdown: 'emails.announcement',
            with: [
                'announcement'        => $this->announcement,
                'recipientName'       => $this->recipientName,
                'typeLabel'           => $this->typeLabel(),
                'badgeBg'             => $badge['bg'],
                'badgeColor'          => $badge['color'],
                'badgeBorder'         => $badge['border'],
                'paymentSubtypeLabel' => $this->paymentSubtypeLabel(),
            ],
        );
    }

    protected function subjectLine(): string
    {
        $prefix = match ($this->announcement->type) {
            'urgent'  => '[Urgent] ',
            'payment' => '[Payment] ',
            'policy'  => '[Policy] ',
            default   => '',
        };

        return $prefix . $this->announcement->title;
    }

    protected function typeLabel(): string
    {
        return match ($this->announcement->type) {
            'urgent'     => 'Urgent',
            'update'     => 'Update',
            'policy'     => 'Policy',
            'payment'    => 'Payment',
            'update_app' => 'App Update',
            default      => 'General',
        };
    }

    protected function badgeColors(): array
    {
        return match ($this->announcement->type) {
            'urgent' => ['bg' => '#fef2f2', 'color' => '#dc2626', 'border' => '#fecaca'],
            'payment' => ['bg' => '#fef3e2', 'color' => '#b45309', 'border' => '#fed7aa'],
            'policy' => ['bg' => '#eff6ff', 'color' => '#1d4ed8', 'border' => '#bfdbfe'],
            'update', 'update_app' => ['bg' => '#f0fdf4', 'color' => '#16a34a', 'border' => '#bbf7d0'],
            default => ['bg' => '#fff7ed', 'color' => '#f97316', 'border' => '#fed7aa'],
        };
    }

    protected function paymentSubtypeLabel(): ?string
    {
        if (!$this->announcement->payment_subtype) {
            return null;
        }

        return match ($this->announcement->payment_subtype) {
            'missed_payment'      => 'Missed Payment',
            'payment_overdue'     => 'Overdue',
            'payment_reminder'    => 'Reminder',
            'payment_received'    => 'Received',
            'account_up_to_date'  => 'Up to Date',
            'payment_failed'      => 'Failed',
            default                => ucfirst(str_replace('_', ' ', $this->announcement->payment_subtype)),
        };
    }
}
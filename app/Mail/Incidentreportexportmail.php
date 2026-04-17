<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class IncidentReportExportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $dateFrom,
        public string $dateTo,
        public int    $total,
        public array  $formats,
        public array  $attachments,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Echo Link — Incident Reports Export (' . $this->dateFrom . ' to ' . $this->dateTo . ')',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.incident-report-export',
            with: [
                'dateFrom' => $this->dateFrom,
                'dateTo'   => $this->dateTo,
                'total'    => $this->total,
                'formats'  => $this->formats,
            ],
        );
    }

    public function build(): static
    {
        foreach ($this->attachments as $file) {
            $this->attachData($file['content'], $file['name'], ['mime' => $file['mime']]);
        }
        return $this;
    }
}
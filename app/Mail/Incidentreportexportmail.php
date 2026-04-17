<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
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
        public array  $exportFiles, // [['path'=>'...','name'=>'...','mime'=>'...']] OR [['content'=>'...','name'=>'...','mime'=>'...']]
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Echo Link — Incident Reports (' . $this->dateFrom . ' to ' . $this->dateTo . ')',
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

    public function attachments(): array
    {
        return array_map(function ($file) {
            if (isset($file['path'])) {
                return Attachment::fromPath($file['path'])
                    ->as($file['name'])
                    ->withMime($file['mime']);
            }

            return Attachment::fromData(
                fn() => $file['content'],
                $file['name'],
            )->withMime($file['mime']);
        }, $this->exportFiles);
    }
}

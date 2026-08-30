<?php

namespace App\Mail;

use App\Models\Channel;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued from SendEstateMonthlyReports; pdfPath is a local/public-disk
 * absolute path from Storage::path(). If estate-reports ever moves to
 * S3, Attachment::fromPath() needs to become Attachment::fromStorageDisk().
 */
class EstateMonthlyReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Channel $channel,
        public array $data,
        public CarbonInterface $periodFrom,
        public string $pdfPath,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->channel->name} — Monthly Safety Report ({$this->periodFrom->format('F Y')})",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.estate-monthly-report',
            with: [
                'channelName' => $this->channel->name,
                'periodLabel' => $this->periodFrom->format('F Y'),
                'data' => $this->data,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->pdfPath)
                ->as("estate-report-{$this->periodFrom->format('Y-m')}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
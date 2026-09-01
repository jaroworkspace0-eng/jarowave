<?php

namespace App\Mail;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentSuccessMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string   $userName,
        public readonly ?string  $amount,
        public readonly ?string  $periodEnd,
        public readonly ?Invoice $invoice = null,
        // The subscriber's own recurring monthly rate (household price for a
        // primary account, linked-account price for a linked account). This
        // is what renders in the "Subscription" row — never hardcode it in
        // the blade, it varies by channel and by primary/linked status.
        public readonly ?string  $monthlyPrice = null,
        // Only set for linked accounts: the primary account holder's name,
        // so the linked user understands whose payment triggered this email
        // and why their SOS access just renewed.
        public readonly ?string  $primaryPayerName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment Successful — Echo Link',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.payment.successful',
        );
    }

    public function attachments(): array
    {
        if (!$this->invoice) return [];

        $pdf = Pdf::loadView('invoices.pdf', ['invoice' => $this->invoice]);

        return [
            Attachment::fromData(
                fn () => $pdf->output(),
                $this->invoice->invoice_number . '.pdf'
            )->withMime('application/pdf'),
        ];
    }
}
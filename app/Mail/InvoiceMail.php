<?php

namespace App\Mail;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function build(): self
    {
        $invoice = $this->invoice;

        $pdf = Pdf::loadView('invoices.pdf', ['invoice' => $invoice])
            ->setPaper('a4')
            ->output();

        return $this->subject("Invoice {$invoice->invoice_number} — Echo Link")
            ->markdown('emails.invoice')
            ->with(['invoice' => $invoice])
            ->attachData($pdf, "invoice-{$invoice->invoice_number}.pdf", [
                'mime' => 'application/pdf',
            ]);
    }
}
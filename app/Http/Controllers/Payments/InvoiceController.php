<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Mail\InvoiceMail;
use App\Models\Client;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class InvoiceController extends Controller
{
    // GET /api/invoices
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            $invoices = Invoice::with(['client.user', 'payment'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);
        } else {
            $client = Client::where('user_id', $user->id)->firstOrFail();

            $invoices = Invoice::with(['payment'])
                ->where('client_id', $client->id)
                ->orderBy('created_at', 'desc')
                ->paginate(20);
        }

        return response()->json(['invoices' => $invoices]);
    }

    // GET /api/invoices/{invoice}
    public function show(Request $request, Invoice $invoice)
    {
        $this->authorise($invoice);

        return response()->json([
            'invoice' => $invoice->load(['client.user', 'payment.subscription']),
        ]);
    }

    // GET /api/invoices/{invoice}/pdf
    public function download(Invoice $invoice)
    {
        $this->authorise($invoice);

        $invoice->load(['client.user', 'payment.subscription']);

        $pdf = Pdf::loadView('invoices.pdf', ['invoice' => $invoice])
            ->setPaper('a4');

        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    }

    // GET /api/invoices/{invoice}/print
    public function print(Invoice $invoice)
    {
        $this->authorise($invoice);

        $invoice->load(['client.user', 'payment.subscription']);

        $pdf = Pdf::loadView('invoices.pdf', ['invoice' => $invoice])
            ->setPaper('a4');

        return $pdf->stream("invoice-{$invoice->invoice_number}.pdf");
    }

    // POST /api/invoices/{invoice}/send
    public function send(Invoice $invoice)
    {
        $this->authorise($invoice);

        $invoice->load(['client.user', 'payment.subscription']);

        Mail::to($invoice->client->user->email)
            ->send(new InvoiceMail($invoice));

        $invoice->update(['sent_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Invoice sent to ' . $invoice->client->user->email,
        ]);
    }

    private function authorise(Invoice $invoice): void
    {
        $user = auth()->user();
        if ($user->role === 'admin') return;

        $client = Client::where('user_id', $user->id)->firstOrFail();
        abort_if($invoice->client_id !== $client->id, 403, 'Unauthorised');
    }
}
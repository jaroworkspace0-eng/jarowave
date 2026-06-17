<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () { 
    return Inertia::render('auth/Login'); 
})->name('home');

// Route::middleware([])->group(function () {
Route::get('/dashboard', function(){
    return Inertia::render('Dashboard');
})->name('dashboard');

Route::get('clients', function(){
    return Inertia::render('Clients/Index');
})->name('clients');

Route::get("employees", function(){
    return Inertia::render('employees/index');
});

Route::get('channels', function(){
    return Inertia::render('Channels/index');
});

Route::get('announcements', function(){
    return Inertia::render('Announcements/Index');
});

Route::get('/deletion-requests', fn() => inertia('DeletionRequests/Index'));

Route::get('/emergencies', fn() => inertia('Emergencies/Index'));


Route::get('/payment/thank-you', function () {
    return inertia('Payment/ThankYou');
});

Route::get('/billing', function () {
    return inertia('Billing/Index');
});

Route::get('/invoices', function () {
    return inertia('Billing/Invoices');
});

Route::get('/payouts', function () {
    return inertia('Billing/Payouts');
});


Route::get('/admin/simulate-payment', function () {
    abort_if(app()->environment('production'), 403);
    return inertia('Admin/PaymentSimulator');
});


Route::get('/admin/incident-reports', function() {
return inertia('Admin/IncidentReports');
});

Route::get('/dv-recordings', function() {
    return inertia('DvMonitor');
});


// ── Guardian Reports (admin) ──────────────────────────────

Route::get('/guardian-reports', function() {
    return inertia('Guardian/Reports');
});

Route::get('/guardian-reports/{id}', function() {
    return inertia('Guardian/ReportDetail');
});

Route::get('/households/{id}/pairings', function() {
    return inertia('Households/Pairings');
});

Route::get('/clients/{id}/checkpoints', function () {
    return inertia('Clients/Checkpoints');
});

Route::get('/admin/process-payouts', function () {
    return inertia('Admin/AdminPayouts');
});

Route::get('/estate/dashboard', function () {
    return inertia('Estate/EstateBillingDashboard');
});

Route::get('/estate/invoices', function () {
    return inertia('Estate/EstateInvoices');
});

Route::get('/admin/estate-payments', function () {
    return inertia('Admin/EstatePayments');
});

Route::get('/admin/gate-guard-payouts', function () {
    return inertia('Admin/GateGuardPayouts');
});


Route::get('/estate/invoices/{invoice}/download', function (\App\Models\Invoice $invoice) {
    if (! request()->hasValidSignature()) {
        abort(403, 'Invalid or expired link.');
    }

    // Ownership check — uid was signed into the URL so it can't be tampered with
    if ((int) request()->query('uid') !== $invoice->client_id) {
        abort(403, 'Access denied.');
    }

    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('invoices.estate-bulk', [
        'invoice'      => $invoice,
        'subscription' => $invoice->channelSubscription()->with('channel')->first(),
        'payment'      => $invoice->channelSubscriptionPayment()->first(),
        'contact'      => \App\Models\ChannelBillingContact::where('user_id', $invoice->client_id)
                            ->where('is_active', true)->first(),
    ])->setPaper('a4', 'portrait')
      ->setOptions([
          'defaultFont'          => 'DejaVu Sans',
          'isHtml5ParserEnabled' => true,
          'isRemoteEnabled'      => false,
          'dpi'                  => 96,
      ]);

    return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
})->name('estate.invoice.download');



require __DIR__.'/settings.php';

Route::resource("users", UserController::class);
Route::get('/search', [SearchController::class, 'index'])->name('search.index');


<?php


use App\Http\Controllers\UserController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

// Route::get('/', function () {
//     return Inertia::render('auth/Login', [
//         'canRegister' => Features::enabled(Features::registration()),
//     ]);
// })->name('home');

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


Route::resource("users", UserController::class);
Route::get('/search', [SearchController::class, 'index'])->name('search.index');
// });

require __DIR__.'/settings.php';

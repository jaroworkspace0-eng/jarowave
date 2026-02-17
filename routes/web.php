<?php


use App\Http\Controllers\UserController;
use App\Http\Controllers\SearchController;
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


Route::resource("users", UserController::class);
Route::get('/search', [SearchController::class, 'index'])->name('search.index');
// });

require __DIR__.'/settings.php';

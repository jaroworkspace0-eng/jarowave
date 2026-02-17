<?php

use App\Http\Controllers\BroadcastAudioController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\UserController;
use App\Models\User;
// use Illuminate\Container\Attributes\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

Route::post('/login', function (Request $request) {
    $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    // 1. Eager load the employee and channels immediately to save database queries
    $user = User::with('employee.channel')->where('email', $request->email)->first();

    if (! $user || ! Hash::check($request->password, $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.'],
        ]);
    }

    // 2. Block Admins
    if ($user->role === 'admin' && $request->source !== 'web') {
        return response()->json([
            'status' => 'error',
            'message' => 'Access denied. Admins must use the web dashboard.'
        ], 403);
    }

     if ($user->role === 'employee' && $request->source !== 'app') {
        return response()->json([
            'status' => 'error',
            'message' => 'Access denied. Employees must use the mobile app.'
        ], 403);
    }

    if($user->is_active === 0) {
        return response()->json([
            'status' => 'error',
            'message' => 'Your account is inactive. Please contact the administrator.'
        ], 403);
    }

    $user->tokens()->delete();
    $token = $user->createToken('mobile')->plainTextToken;

    $channels = $user->employee?->channel->map(function($channel) {
        return [
            'id' => $channel->id,
            'name' => $channel->name,
            // Assuming your Channel model has a relationship to a Company/Client
        ];
    }) ?? collect([]);


    return response()->json([
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'occupation' => $user->occupation,
            'user_id' => $user->id,
        ],
        'channels' => $channels,
        'token' => $token,
    ]);
});


Route::middleware(['auth:sanctum'])->group(function () { 
    Route::get('/user', function (Request $request) {
    return $request->user();
});
    Route::post('/broadcast-audio', [BroadcastAudioController::class, 'store'])->name('broadcast.audio');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('index.dashboard');
    Route::post('/user/update-status', [StatusController::class, 'updateStatus']) ->name('user.update-status');
    Route::resource('clients', ClientController::class);
    Route::patch('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');
    Route::patch('/channels/{channel}/toggle-status', [ChannelController::class, 'toggleStatus']);
    Route::patch('/clients/{client}/toggle-status', [ClientController::class, 'toggleStatus']);
    Route::resource('employees', EmployeeController::class);
    Route::resource('channels', ChannelController::class);
    Route::get('clients/list', [ClientController::class, 'clients']);
    Route::get('/channels-list', [ChannelController::class, 'getChannels']);

   
});

// Route::resource("employees", EmployeeController::class);
Route::prefix('v1')->name('api.')->group(function () {
    
    
    Route::get('/search', [SearchController::class, 'index'])->name('search.index');

    // Route::get('channels/{channel}/units', [ChannelController::class, 'getUnits']);
});

Route::get('channels/{channel}/units', [ChannelController::class, 'getUnits']);



// 2p&+[085DiEc


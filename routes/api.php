<?php


use App\Http\Controllers\BroadcastAudioController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmergencyAlertController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LiveKitController;
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
        'email'    => 'required|string',
        'password' => 'required',
    ]);

    $login = $request->email;

    // Detect format: email vs phone
    if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
        $user = User::with('employee.client','employee.channels')
            ->where('email', $login)
            ->first();
    } elseif (preg_match('/^\+?\d{7,15}$/', $login)) {
        $user = User::with('employee.client','employee.channels')
            ->where('phone', $login)
            ->first();
    } else {
        throw ValidationException::withMessages([
            'email' => ['Login must be a valid email or phone number.'],
        ]);
    }

    if (! $user || ! Hash::check($request->password, $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.'],
        ]);
    }

    // Role restrictions
    if ($user->role === 'admin' && $request->source !== 'web') {
        return response()->json([
            'status'  => 'error',
            'message' => 'Access denied. Admins must use the web dashboard.'
        ], 403);
    }

    $appRoles = ['employee','household','resident'];
    if (in_array($user->role, $appRoles) && $request->source !== 'app') {
        return response()->json([
            'status'  => 'error',
            'message' => 'Access denied. Please use the mobile application to manage your profile.'
        ], 403);
    }

    // 🔹 Block if user OR their client is inactive
    if ($user->is_active === 0 || $user->employee?->client?->is_active === 0) {
        $orgName = $user->employee?->client?->name ?? 'your organization management';
        return response()->json([
            'status'  => 'error',
            'message' => "Account inactive. Please contact {$orgName} to restore service."
        ], 403);
    }

    // Reset old tokens
    $user->tokens()->delete();
    $tokenName = $request->source === 'app' ? 'mobile-access' : 'web-access';
    $token = $user->createToken($tokenName)->plainTextToken;

    $channels = $user->employee?->channels->map(function ($channel) {
        return [
            'id'   => $channel->id,
            'name' => $channel->name,
        ];
    }) ?? collect([]);

    return response()->json([
        'user' => [
            'id' => $user->id,
            'user_id'   => $user->id,
            'employee_id' => $user->employee?->id,  // ← this line
            'name'      => $user->name,
            'email'     => $user->email,
            'phone'     => $user->phone,
            'occupation'=> $user->occupation,
            'role'      => $user->role,
            'address' => $user->address_line_1,
            'suburb' => $user->suburb,
            'longitude' => $user->longitude,
            'latitude' => $user->latitude,
            'complex' => $user->complex_name
        ],
        'channels' => $channels,
        'token'    => $token,
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
    Route::post('/emergency-alerts', [EmergencyAlertController::class, 'store']);
    Route::patch('/emergency-alerts/{alert}', [EmergencyAlertController::class, 'update']);
    Route::patch('/emergency-resolutions', [EmergencyAlertController::class, 'emergencyResolutionUpdate']);
    Route::post('/emergency/accept', [EmergencyAlertController::class, 'alertAccept']);
    // Route::post('/livekit/token', [LiveKitController::class, 'generateToken']);
});

Route::post('/channels/assign', [ChannelController::class, 'assignToUser']);

// -------------------------------

Route::post('/livekit/token', [LiveKitController::class, 'generateToken'])->middleware('auth:sanctum');

// Route::resource("employees", EmployeeController::class);
Route::prefix('v1')->name('api.')->group(function () {
    
    
    Route::get('/search', [SearchController::class, 'index'])->name('search.index');

    // Route::get('channels/{channel}/units', [ChannelController::class, 'getUnits']);
});

Route::get('channels/{channel}/units', [ChannelController::class, 'getUnits']);



// 2p&+[085DiEc


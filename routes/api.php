<?php

use App\Http\Controllers\AccountDeletionController;
use App\Http\Controllers\Admin\AdminSubscriptionController;
use App\Http\Controllers\Admin\PaymentSimulatorController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\Api\SosIncidentReportController;
use App\Http\Controllers\BroadcastAudioController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmergencyAlertController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\HouseholdController;
use App\Http\Controllers\IncidentReportExportController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\LiveKitController;
use App\Http\Controllers\Payments\EarningController;
use App\Http\Controllers\Payments\InvoiceController;
use App\Http\Controllers\Payments\OzowRecoveryWebhookController;
use App\Http\Controllers\Payments\OzowWebhookController;
use App\Http\Controllers\Payments\PayfastRecoveryWebhookController;
use App\Http\Controllers\Payments\PayfastWebhookController;
use App\Http\Controllers\Payments\PaymentController;
use App\Http\Controllers\Payments\PaymentRecoveryController;
use App\Http\Controllers\Payments\SubscriptionController;
use App\Http\Controllers\Payments\SubscriptionPaymentController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\UserController;
use App\Models\Channel;
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
            'employee_id' => $user->employee?->id,
            'name'      => $user->name,
            'organisation_type' => $user->organisation_type,
            'organisation_name' => $user->organisation_name,
            'email'     => $user->email,
            'phone'     => $user->phone,
            'occupation'=> $user->occupation,
            'role'      => $user->role,
            'address' => $user->address_line_1,
            'suburb' => $user->suburb,
            'longitude' => $user->longitude,
            'latitude' => $user->latitude,
            'complex' => $user->complex_name,
            'safe_cancel_pin' => $user->safe_cancel_pin,
            'duress_pin' => $user->duress_pin,
            'unit_number' => $user->unit_number,
            'plan'              => $user->plan,
        ],
        'channels' => $channels,
        'token'    => $token,
    ]);
});


// These endpoints are called by payment gateways, so they must be publicly accessible and should not require authentication.
Route::post('/webhooks/payfast', [PayfastWebhookController::class, 'handle']);
Route::post('/webhooks/ozow',    [OzowWebhookController::class, 'handle']);

// Client registration route (public)
Route::post('/register', [ClientController::class, 'register']);

// Account deletion request route (public)
Route::post('/account/deletion-request', [AccountDeletionController::class, 'store']);

// Public — no auth needed (for register.html to validate token)
Route::get('/household/invite/{token}', [InviteController::class, 'validate']);


// Household auth routes (public)
Route::post('/household/login', [HouseholdController::class, 'login']);
Route::post('/household/register', [HouseholdController::class, 'register']);
Route::get('/household/invite/{token}', [HouseholdController::class, 'validateInvite']);



// Payment notifications (public, called by payment gateways, but we can add a secret token for security if needed)
Route::post('/notifications/payment-failed', [PaymentController::class, 'notifyPaymentFailed']);

// PayFast webhook — no auth middleware, PayFast signs the payload
Route::post('/webhooks/payfast', [PaymentController::class, 'handlePayfastWebhook']);
Route::post('/webhooks/ozow', [PaymentController::class, 'handleOzowWebhook']);

// Recovery webhooks — no auth, verified by signature/hash inside controller
Route::post('/webhooks/payfast/recovery', [PayfastRecoveryWebhookController::class, 'handle']);
Route::post('/webhooks/ozow/recovery',    [OzowRecoveryWebhookController::class,    'handle']);

Route::get('/internal/payment-failures', [PaymentRecoveryController::class, 'activeFailures']);



// Household routes (require auth)
Route::middleware('auth:sanctum')->prefix('household')->group(function () {

    // ---------------------------------------------------------------

    Route::post('/payments/subscription/cancel', [PaymentRecoveryController::class, 'cancelSubscription']);

    Route::post('/payments/recovery/update-url', [PaymentRecoveryController::class, 'getUpdateUrl']);

    // Generate recovery payment link (called by the app)
    Route::post('/payments/recovery/generate', [PaymentRecoveryController::class, 'generateLink']);

    Route::get('/payment-url', [HouseholdController::class, 'paymentUrl']);
    Route::get('/subscription', [HouseholdController::class, 'subscription']);
    Route::post('/subscription/cancel', [HouseholdController::class, 'cancelSubscription']);
    Route::get('/invoices', [HouseholdController::class, 'invoices']);
    Route::get('/invoices/{id}/pdf', [HouseholdController::class, 'invoicePdf']);
    Route::get('/invoices/{id}/print', [HouseholdController::class, 'invoicePrint']);
    Route::post('/invoices/{id}/send', [HouseholdController::class, 'invoiceSend']);
});


Route::middleware(['auth:sanctum'])->group(function () { 
        Route::get('/user', function (Request $request) {
        return $request->user();
    });


    // 
    Route::prefix('admin/incident-reports')->group(function () {
        Route::get('/export/pdf',   [IncidentReportExportController::class, 'exportPdf']);
        Route::get('/export/csv',   [IncidentReportExportController::class, 'exportCsv']);
        Route::post('/export/email',[IncidentReportExportController::class, 'emailExport']);
    });


    // Patroller — submit and view own reports
    Route::post('/incident-reports', [SosIncidentReportController::class, 'store']);
    Route::get('/incident-reports', [SosIncidentReportController::class, 'index']);

    // Admin — all reports + actions
    Route::prefix('admin')->group(function () {
        Route::get('/incident-reports', [SosIncidentReportController::class, 'adminIndex']);
        Route::get('/incident-reports/{report}', [SosIncidentReportController::class, 'show']);
        Route::post('/incident-reports/{report}/action', [SosIncidentReportController::class, 'action']);
    });

    // Household — view reports on their own alerts
    Route::get('/household/incident-reports', [SosIncidentReportController::class, 'householdReports']);


    // Admin subscription management routes (only for admin users, but we can check that inside the controller since these are under the household prefix)
    Route::prefix('admin/subscriptions')->group(function () {
        Route::get('/',                                    [AdminSubscriptionController::class, 'index']);
        Route::get('/{subscription}/payments',             [AdminSubscriptionController::class, 'payments']);
        Route::post('/{subscription}/eft-payment',         [AdminSubscriptionController::class, 'markEftPaid']);
        Route::post('/{subscription}/suspend',             [AdminSubscriptionController::class, 'suspend']);
        Route::post('/{subscription}/unsuspend',           [AdminSubscriptionController::class, 'unsuspend']);
        Route::post('/{subscription}/cancel',              [AdminSubscriptionController::class, 'cancel']);
        Route::post('/{subscription}/conduct-block',   [AdminSubscriptionController::class, 'conductBlock']);
        Route::post('/{subscription}/conduct-unblock', [AdminSubscriptionController::class, 'conductUnblock']);
        Route::post('/{subscription}/activation-fee', [AdminSubscriptionController::class, 'markActivationFeePaid']);
    });


    // Payment simulator — non-production only
    // Route::get('/admin/simulate-payment/users', [PaymentSimulatorController::class, 'index']);
    Route::post('/admin/simulate-payment', [PaymentSimulatorController::class, 'simulate']);
    Route::get('/admin/simulate-payment/users', [PaymentSimulatorController::class, 'users']);

    // Invite management for employees to invite households/residents
    Route::get('/invite', [InviteController::class, 'show']);
    Route::post('/invite/generate', [InviteController::class, 'generate']);
    Route::post('/invite/{id}/regenerate', [InviteController::class, 'regenerate']);
    Route::delete('/invite/{id}', [InviteController::class, 'destroy']);

    Route::post('/broadcast-audio', [BroadcastAudioController::class, 'store'])->name('broadcast.audio');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('index.dashboard');
    Route::post('/user/update-status', [StatusController::class, 'updateStatus']) ->name('user.update-status');
    Route::resource('clients', ClientController::class);
    Route::patch('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');
    Route::patch('/channels/{channel}/toggle-status', [ChannelController::class, 'toggleStatus']);
    Route::patch('/clients/{client}/toggle-status', [ClientController::class, 'toggleStatus']);
    Route::resource('employees', EmployeeController::class);
    Route::get('clients/list', [ClientController::class, 'clients']);
    Route::get('/channels-list', [ChannelController::class, 'getChannels']);
    Route::post('/emergency-alerts', [EmergencyAlertController::class, 'store']);
    Route::patch('/emergency-alerts/{alert}', [EmergencyAlertController::class, 'update']);
    Route::patch('/emergency-resolutions', [EmergencyAlertController::class, 'emergencyResolutionUpdate']);
    Route::post('/emergency/accept', [EmergencyAlertController::class, 'alertAccept']);

    // get emergency resolution details for a specific alert
    Route::patch('/emergency-resolutions/arrived', [EmergencyAlertController::class, 'recordArrival']);

    // NEW: victim confirmation endpoint
    Route::patch('/emergency-resolutions/{alertId}/confirm', [EmergencyAlertController::class, 'confirm']);

    Route::get('/emergency-alerts', [EmergencyAlertController::class, 'list']);
    Route::delete('/emergency-alerts/{id}', [EmergencyAlertController::class, 'destroy']);
    Route::patch('/emergency-alerts/{id}/resolve', [EmergencyAlertController::class, 'resolve']);
    
    Route::get('/announcements',         [AnnouncementController::class, 'index']);
    Route::post('/announcements/send',   [AnnouncementController::class, 'send']);
    Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);


    // account deletion request actions
    Route::get('/account/deletion-requests', [AccountDeletionController::class, 'index']);
    Route::patch('/account/deletion-requests/{id}/cancel', [AccountDeletionController::class, 'cancel']);
    Route::delete('/account/deletion-requests/{id}', [AccountDeletionController::class, 'destroy']);


    // Subscriptions
    Route::get('/subscriptions',                    [SubscriptionController::class, 'index']);
    Route::get('/subscriptions/{subscription}',     [SubscriptionController::class, 'show']);
    Route::patch('/subscriptions/{subscription}/cancel',  [SubscriptionController::class, 'cancel']);
    Route::patch('/subscriptions/{subscription}/upgrade', [SubscriptionController::class, 'upgrade']);

    // Payments
    Route::get('/payments',          [SubscriptionPaymentController::class, 'index']);
    Route::get('/payments/{payment}', [SubscriptionPaymentController::class, 'show']);

    // Earnings
    Route::get('/earnings',          [EarningController::class, 'index']);
    Route::get('/earnings/summary',  [EarningController::class, 'summary']);
    Route::get('/earnings/{earning}', [EarningController::class, 'show']);


    Route::post('/payments/initiate', [PaymentController::class, 'initiate']);


    // Invoices
    Route::get('/invoices',                  [InvoiceController::class, 'index']);
    Route::get('/invoices/{invoice}',        [InvoiceController::class, 'show']);
    Route::get('/invoices/{invoice}/pdf',    [InvoiceController::class, 'download']);
    Route::get('/invoices/{invoice}/print',  [InvoiceController::class, 'print']);
    Route::post('/invoices/{invoice}/send',  [InvoiceController::class, 'send']);



    Route::get('/channels/mine', function (Request $request) {
        $client = $request->user()->client;

        if (!$client) {
            return response()->json([]);
        }

        $channels = Channel::where('client_id', $client->id)->get();

        return response()->json($channels);
    });


    Route::resource('channels', ChannelController::class);

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


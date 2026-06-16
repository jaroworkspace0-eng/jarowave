<?php

use App\Http\Controllers\AccountDeletionController;
use App\Http\Controllers\Admin\AdminGuardPayoutController;
use App\Http\Controllers\Admin\AdminPayoutController;
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
use App\Http\Controllers\Admin\IncidentReportExportController;
use App\Http\Controllers\Api\GuardianIncidentController;
use App\Http\Controllers\Api\GuardianReportController;
use App\Http\Controllers\Api\GuardianResponseController;
use App\Http\Controllers\Api\HouseholdPairingController;
use App\Http\Controllers\Api\UserNotificationController;
use App\Http\Controllers\BlockedHouseholdController;
use App\Http\Controllers\ChannelBillingController;
use App\Http\Controllers\CheckpointController;
use App\Http\Controllers\DvRecordingController;
use App\Http\Controllers\HouseholdSettingController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\LiveKitController;
use App\Http\Controllers\PatrolController;
use App\Http\Controllers\Payments\EarningController;
use App\Http\Controllers\Payments\HouseholdPayoutController;
use App\Http\Controllers\Payments\InvoiceController;
use App\Http\Controllers\Payments\OzowRecoveryWebhookController;
use App\Http\Controllers\Payments\OzowWebhookController;
use App\Http\Controllers\Payments\PayfastRecoveryWebhookController;
use App\Http\Controllers\Payments\PayfastWebhookController;
use App\Http\Controllers\Payments\PaymentController;
use App\Http\Controllers\Payments\PaymentRecoveryController;
use App\Http\Controllers\Payments\PayoutController;
use App\Http\Controllers\Payments\SubscriptionController;
use App\Http\Controllers\Payments\SubscriptionPaymentController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VisitorCodeController;
use App\Models\Channel;
use App\Models\ChannelBillingContact;
use App\Models\Invoice;
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
            'billing_model' => $channel->billing_model,
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
            'is_estate' => $user->is_estate,
            'is_estate_opted_in' => $user->subscription()
                ->where('cancellation_reason', 'estate_optin')
                ->whereNotNull('channel_subscription_id')
                ->exists(),
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


// MUST be outside — standalone, no middleware

Route::post('/household/payments/subscription/cancel', [PaymentRecoveryController::class, 'cancelSubscription']);
Route::post('/household//payments/recovery/update-url', [PaymentRecoveryController::class, 'getUpdateUrl']);
// Generate recovery payment link (called by the app)
Route::post('/household/payments/recovery/generate', [PaymentRecoveryController::class, 'generateLink']);




// Payment notifications (public, called by payment gateways, but we can add a secret token for security if needed)
Route::post('/notifications/payment-failed', [PaymentController::class, 'notifyPaymentFailed']);

// PayFast webhook — no auth middleware, PayFast signs the payload
// Route::post('/webhooks/payfast', [PaymentController::class, 'handlePayfastWebhook']);
Route::post('/webhooks/ozow', [PaymentController::class, 'handleOzowWebhook']);

// Recovery webhooks — no auth, verified by signature/hash inside controller
Route::post('/webhooks/payfast/recovery', [PayfastRecoveryWebhookController::class, 'handle']);
Route::post('/webhooks/ozow/recovery',    [OzowRecoveryWebhookController::class,    'handle']);

Route::get('/internal/payment-failures', [PaymentRecoveryController::class, 'activeFailures']);

// DV recording finalisation endpoint — called internally by the recording service when it finishes processing a recording. Secured by a strong secret token in the header.
Route::post('/internal/dv-recordings/{alertId}/finalise', [DvRecordingController::class, 'finalise']);

Route::patch('/internal/dv-recordings/{alertId}/set-cancel-pin', [DvRecordingController::class, 'cancelPin']);
Route::patch('/internal/emergency-alerts/{alert}/set-cancel-pin', [EmergencyAlertController::class, 'cancelPin']);


// MUST be outside — standalone, no middleware
Route::get('/dv-recordings/{alertId}/stream', [DvRecordingController::class, 'stream']);


// ── Internal endpoints (Node server only, protected by PTT secret) ──────────
Route::get('internal/users/{userId}/fcm-token', [UserController::class, 'getFcmToken']);
// Route::get('users/{user}/fcm-token', [UserController::class, 'getFcmToken']);

// Household routes (require auth)
Route::middleware('auth:sanctum')->prefix('household')->group(function () {

    // ---------------------------------------------------------------


    Route::get('/payment-url', [HouseholdController::class, 'paymentUrl']);
    Route::get('/subscription', [HouseholdController::class, 'subscription']);
    Route::post('/subscription/cancel', [HouseholdController::class, 'cancelSubscription']);
    Route::get('/invoices', [HouseholdController::class, 'invoices']);
    Route::get('/invoices/{id}/pdf', [HouseholdController::class, 'invoicePdf']);
    Route::get('/invoices/{id}/print', [HouseholdController::class, 'invoicePrint']);
    Route::post('/invoices/{id}/send', [HouseholdController::class, 'invoiceSend']);
    Route::get('/list', [EmployeeController::class, 'householdList']);
    Route::post('/reactivate', [HouseholdController::class, 'reactivate']);
    Route::post('/pay-now-onetime', [HouseholdController::class, 'payNowOnetime']);

    // Household routes
    Route::post('/pay-now', [HouseholdController::class, 'payNow']);
    Route::post('/visitor-codes', [VisitorCodeController::class, 'generate']);
    Route::get('/visitor-codes', [VisitorCodeController::class, 'index']);
    Route::delete('/visitor-codes/{id}', [VisitorCodeController::class, 'revoke']);

    
});


// Checkpoint routes (require auth, and client-specific prefix to ensure guards can only access checkpoints for their own client)
Route::middleware('auth:sanctum')->prefix('clients')->group(function () {
    Route::get('/{clientId}/checkpoints', [CheckpointController::class, 'index']);
    Route::post('/{clientId}/checkpoints', [CheckpointController::class, 'store']);
});


// Checkpoint routes (require auth, but not client-specific prefix since guards need to access them by ID without knowing the client)
Route::middleware('auth:sanctum')->prefix('checkpoints')->group(function () {
    Route::delete('/{id}', [CheckpointController::class, 'destroy']);
    Route::get('/{id}/scans', [CheckpointController::class, 'scans']);
});


Route::middleware('auth:sanctum')->prefix('guard')->group(function () {
    // Guard routes
    Route::post('/visitor-codes/verify', [VisitorCodeController::class, 'verify']);
    Route::post('/patrol/scan', [PatrolController::class, 'scan']);
    Route::get('/patrol/history', [PatrolController::class, 'history']);
});

// Earnings
Route::middleware('auth:sanctum')->prefix('earnings')->group(function () {
Route::get('/',          [EarningController::class, 'index']);
Route::get('/summary',  [EarningController::class, 'summary']);
Route::get('/export',   [EarningController::class, 'export']); 
Route::get('/{earning}', [EarningController::class, 'show']);
});


// Payouts — CPF agent's monthly payout history
Route::middleware('auth:sanctum')->prefix('payouts')->group(function () {
Route::get('/', [PayoutController::class, 'index']);
Route::get('/export',    [PayoutController::class, 'export']); 
});


Route::middleware(['auth:sanctum'])->group(function () { 
        Route::get('/user', function (Request $request) {
        return $request->user();
    });


    Route::post('channel-payments/{payment}/approve', [ChannelBillingController::class, 'approveEftPayment']);
    Route::post('channel-payments/{payment}/reject',  [ChannelBillingController::class, 'rejectEftPayment']);

    // ── ADD THESE ROUTES inside the existing Route::middleware(['auth:sanctum']) group ──
    Route::prefix('admin/payouts')->group(function () {

        // Client payouts
        Route::get('/clients',                          [AdminPayoutController::class, 'clients']);
        Route::get('/clients/{clientId}/earnings',      [AdminPayoutController::class, 'clientEarnings']);
        Route::post('/process',                         [AdminPayoutController::class, 'process']);
        Route::post('/notify-bank-details',             [AdminPayoutController::class, 'notifyBankDetails']);


        // Gate guard payouts
        Route::get('/guards',                           [AdminGuardPayoutController::class, 'guards']);
        Route::get('/guards/{userId}/earnings',         [AdminGuardPayoutController::class, 'guardEarnings']);
        Route::post('/guards/process',                  [AdminGuardPayoutController::class, 'process']);
        Route::post('/guards/notify-bank-details',      [AdminGuardPayoutController::class, 'notifyBankDetails']);
    });

    // ── Checkpoint routes (admin only) ────────────────────────────────────────────
    // Add these inside your existing auth middleware group in routes/api.php

    Route::prefix('clients/{clientId}/checkpoints')->group(function () {
        Route::get('/',                         [CheckpointController::class, 'index']);
        Route::post('/',                        [CheckpointController::class, 'store']);
        Route::patch('/{checkpointId}',         [CheckpointController::class, 'update']);
        Route::delete('/{checkpointId}',        [CheckpointController::class, 'destroy']);
        Route::get('/{checkpointId}/scans',     [CheckpointController::class, 'scans']);
        Route::get('/{checkpointId}/qr',        [CheckpointController::class, 'qrImage']);
    });

    Route::get('clients/{clientId}/all-scans', [CheckpointController::class, 'allScans']);

    Route::get('/app-config', [AnnouncementController::class, 'appConfig']);
    Route::get('/patrollers/list', [EmployeeController::class, 'patrollerList']);

    Route::prefix('guardian-incidents')->group(function () {
        Route::post('/{alertId}/claim',   [GuardianIncidentController::class, 'claim']);
        Route::post('/{alertId}/respond', [GuardianIncidentController::class, 'respond']);
        Route::post('/{alertId}/resolve', [GuardianIncidentController::class, 'resolve']);
        Route::get('/{alertId}/status',   [GuardianIncidentController::class, 'status']);
        Route::post('/{alertId}/household-confirm',   [GuardianIncidentController::class, 'householdConfirm']);
        Route::post('/{alertId}/still-needs-help', [GuardianIncidentController::class, 'stillNeedsHelp']);
        
    });

    Route::post('/blocked-households',            [BlockedHouseholdController::class, 'store']);
    Route::get('/blocked-households',             [BlockedHouseholdController::class, 'index']);
    Route::delete('/blocked-households/{userId}', [BlockedHouseholdController::class, 'destroy']);

    // fcm token management for push notifications
    Route::post('users/fcm-token', [UserController::class, 'updateFcmToken']);

    Route::get('users/search-community', [HouseholdPairingController::class, 'searchCommunity']);


    // ── Household settings ───────────────────────────────────────
    Route::get('/household-settings',   [HouseholdSettingController::class, 'show']);
    Route::patch('/household-settings', [HouseholdSettingController::class, 'update']);

    // ── Pairings ────────────────────────────────────────────────
    Route::prefix('household-pairings')->group(function () {
        Route::get('/',                         [HouseholdPairingController::class, 'index']);
        Route::post('/',                        [HouseholdPairingController::class, 'store']);
        Route::put('/{pairing}/accept',         [HouseholdPairingController::class, 'accept']);
        Route::put('/{pairing}/decline',        [HouseholdPairingController::class, 'decline']);
        Route::delete('/{pairing}',             [HouseholdPairingController::class, 'destroy']);
    });

    Route::get('households/{household}/pairings',   [HouseholdPairingController::class, 'forHousehold']);
    Route::get('households/{household}/guardians',  [HouseholdPairingController::class, 'guardians']);
    // Route::get('/households/search', [HouseholdController::class, 'searchHouseholdToPair']);

    // ── Guardian responses ──────────────────────────────────────
    Route::prefix('alerts/{alertId}')->group(function () {
        Route::get('guardians',                     [GuardianResponseController::class, 'index']);
        Route::post('guardian-response',            [GuardianResponseController::class, 'store']);
    });


    // ── Guardian reports ────────────────────────────────────────
    Route::prefix('guardian-reports')->group(function () {
        Route::get('/',                             [GuardianReportController::class, 'index']);
        Route::post('/',                            [GuardianReportController::class, 'store']);
        Route::get('/{report}',                     [GuardianReportController::class, 'show']);
        Route::put('/{report}/review',              [GuardianReportController::class, 'review']);
        Route::put('/{report}/escalate',            [GuardianReportController::class, 'escalate']);
    });

    Route::post('/dv-audio-upload', [DvRecordingController::class, 'store']);

    // DV recording endpoints
    Route::get('/dv-recording-list', [DvRecordingController::class, 'index']);
    Route::get('/dv-recordings/{alertId}',         [DvRecordingController::class, 'show']);
    // Route::get('/dv-recordings/{alertId}/stream',  [DvRecordingController::class, 'stream']);

    // Admin incident report export routes 
    Route::prefix('admin/incident-reports')->group(function () {
        Route::get('/export/pdf',   [IncidentReportExportController::class, 'exportPdf']);
        // Route::get('/export/csv',   [IncidentReportExportController::class, 'exportCsv']);
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

        // Payment simulator — non-production only;
        Route::post('/simulate-payment', [PaymentSimulatorController::class, 'simulate']);
        Route::get('/simulate-payment/users', [PaymentSimulatorController::class, 'users']);

        // Pending EFT payments for admin to approve/reject
        Route::get('/estate-payments', [ChannelBillingController::class, 'pendingEftPayments']);
        Route::post('/channel-payments/{payment}/approve', [ChannelBillingController::class, 'approveEftPayment']);
        Route::post('/channel-payments/{payment}/reject', [ChannelBillingController::class, 'rejectEftPayment']);
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
    Route::patch('/clients/{client}/toggle-status', [ClientController::class, 'toggleStatus']);
    Route::resource('employees', EmployeeController::class);
    Route::get('clients/list', [ClientController::class, 'clients']);
    Route::post('/emergency-alerts', [EmergencyAlertController::class, 'store']);
    Route::patch('/emergency-alerts/{alert}', [EmergencyAlertController::class, 'update']);
    Route::patch('/emergency-resolutions', [EmergencyAlertController::class, 'emergencyResolutionUpdate']);
    Route::post('/emergency/accept', [EmergencyAlertController::class, 'alertAccept']);

    Route::get('/emergency-alerts/latest/{channelId}', [EmergencyAlertController::class, 'latestForChannel']);

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

    
    // Households — scoped to the logged-in client (for the payout breakdown table)
    Route::get('/households', [HouseholdPayoutController::class, 'households']);
    
    // Bank details — view and save
    Route::get('/bank-details',  [HouseholdPayoutController::class, 'show']);
    Route::post('/bank-details', [HouseholdPayoutController::class, 'store']);


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


    Route::get('/estate/my-channel', function (Request $request) {
    $contact = ChannelBillingContact::where('user_id', $request->user()->id)
        ->where('is_active', true)
        ->with('channel')
        ->firstOrFail();

        return response()->json(['channel' => $contact->channel]);
    });

    Route::get('/estate/invoices', function (Request $request) {
        $contact = ChannelBillingContact::where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->with('channel')
            ->firstOrFail();

        $invoices = Invoice::where('client_id', $request->user()->id)
            ->where('invoice_type', 'estate_bulk')
            ->with('channelSubscriptionPayment', 'channelSubscription.channel')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json(['invoices' => $invoices]);
    });


    Route::get('/estate/invoices/{invoice}/download', function (Request $request, Invoice $invoice) {
        // Ensure this invoice belongs to the authenticated user
        if ($invoice->client_id !== $request->user()->id || $invoice->invoice_type !== 'estate_bulk') {
            abort(403);
        }

        $subscription = $invoice->channelSubscription()->with('channel')->first();
        $payment = $invoice->channelSubscriptionPayment()->first();
        $contact = ChannelBillingContact::where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->first();

        $pdf = Barryvdh\DomPDF\Facade\Pdf::loadView('pdfs.estate-invoice', [
            'invoice'      => $invoice,
            'subscription' => $subscription,
            'payment'      => $payment,
            'contact'      => $contact,
        ]);

        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    });


    Route::resource('channels', ChannelController::class);

    Route::patch('/channels/{channel}/toggle-status', [ChannelController::class, 'toggleStatus']);
    Route::get('/channels-list', [ChannelController::class, 'getChannels']);

    // Channel Billing
    Route::prefix('channels/{channel}/billing')->group(function () {
        Route::get('summary',                    [ChannelBillingController::class, 'summary']);
        Route::get('opted-in-households',        [ChannelBillingController::class, 'optedInHouseholds']);
        Route::get('payment-history',            [ChannelBillingController::class, 'paymentHistory']);
        Route::post('billing-contact',           [ChannelBillingController::class, 'storeBillingContact']);
        Route::patch('billing-contact',          [ChannelBillingController::class, 'updateBillingContact']);
        Route::post('opt-in',                    [ChannelBillingController::class, 'optIn']);
        Route::post('opt-out',                   [ChannelBillingController::class, 'optOut']);
        Route::post('mark-eft-paid',             [ChannelBillingController::class, 'markEftPaid']);
        Route::post('remove-household', [ChannelBillingController::class, 'removeHousehold']);
    });


    // ── Notifications ───────────────────────────────────────
    Route::prefix('notifications')->group(function () {
        Route::get('/',                  [UserNotificationController::class, 'index']);
        Route::get('/unread-count',      [UserNotificationController::class, 'unreadCount']);
        Route::put('/{id}/read',         [UserNotificationController::class, 'markRead']);
        Route::put('/read-all',          [UserNotificationController::class, 'markAllRead']);
        Route::delete('/{id}',           [UserNotificationController::class, 'destroy']);
    });

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


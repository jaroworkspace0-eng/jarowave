<?php

use App\Models\Subscription;
use App\Models\User;
use App\Http\Controllers\Payments\PayfastWebhookController;
use App\Services\PayFastService;
use Illuminate\Http\Request;

// 1. Get or create a mock user
$user = User::first() ?? new User([
    'name'  => 'Test Sandbox',
    'email' => 'test@example.com',
    'phone' => '0821234567',
]);

$merchantReference = 'sandbox-test-' . time();
$payfast = app(PayFastService::class);

// 2. Generate the URL (Added 'billing_date' to resolve warning)
$url = $payfast->buildSubscriptionUrl([
    'name_first'       => explode(' ', $user->name)[0],
    'name_last'        => explode(' ', $user->name, 2)[1] ?? '',
    'email_address'    => $user->email,
    'cell_number'      => $user->phone ?? '',
    'm_payment_id'     => $merchantReference,
    'item_name'        => 'Echo Link Community Protection',
    'item_description' => '14-day free trial then R80 per month neighbourhood watch subscription',
    'billing_date'     => now()->addDay()->toDateString(), // Fix 1: Added billing_date
]);

dump(['Generated Subscription URL' => $url]);

// 3. Find an existing subscription OR pass client_id (Fix 2)
$subscription = Subscription::first();

if ($subscription) {
    // Reuse existing subscription for testing
    $subscription->update(['merchant_reference' => $merchantReference]);
} else {
    // Fallback: Create new record with required client_id
    $subscription = Subscription::create([
        'merchant_reference' => $merchantReference,
        'user_id'            => $user->id ?? 1,
        'client_id'          => 1, // Fix 2: Provide required foreign key
        'status'             => 'pending',
    ]);
}

// 4. Build ITN callback payload sent by PayFast
$payload = [
    'm_payment_id'     => $merchantReference,
    'pf_payment_id'    => '10023456',
    'payment_status'   => 'COMPLETE',
    'item_name'        => 'Echo Link Community Protection',
    'item_description' => '14-day free trial then R80 per month neighbourhood watch subscription',
    'amount_gross'     => '0.00', // R0.00 trial setup
    'amount_fee'       => '0.00',
    'amount_net'       => '0.00',
    'name_first'       => explode(' ', $user->name)[0],
    'name_last'        => explode(' ', $user->name, 2)[1] ?? '',
    'email_address'    => $user->email,
    'merchant_id'      => config('services.payfast.merchant_id'),
    'token'            => '47b193d5-2248-4e12-892f-' . time(),
];

// 5. Generate MD5 signature matching PayFast requirements
$passphrase = config('services.payfast.passphrase');
$pfOutput = '';
foreach ($payload as $key => $val) {
    if ($val !== '') {
        $pfOutput .= $key . '=' . urlencode(trim($val)) . '&';
    }
}
$getString = substr($pfOutput, 0, -1);
if (!empty($passphrase)) {
    $getString .= '&passphrase=' . urlencode(trim($passphrase));
}
$payload['signature'] = md5($getString);

// 6. Invoke controller directly
$request = Request::create('/api/webhooks/payfast', 'POST', $payload);
$controller = app(PayfastWebhookController::class);

$response = $controller->handle($request, $payfast);

dump([
    'HTTP Status'   => $response->getStatusCode(),
    'Response Body' => $response->getContent(),
    'Saved Token'   => $subscription->fresh()->payfast_token ?? null,
]);
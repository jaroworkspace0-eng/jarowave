<?php

$token = '28e19b56-91d8-4070-9ad8-fd4423074a4a';
$merchantId = '10052172';
$passphrase = 'E2yzIIvSW6c00Y77Z4RdFQGCCXcsjJoP';
$timestamp = now()->toIso8601String();
$version = 'v1';
$amount = 2500;
$itemName = 'Echo Link Community Protection';
$itemDescription = 'echo-link-adhoc-charge';
$mPaymentId = 'test-' . uniqid();

$parts = [
    'amount=' . urlencode($amount),
    'item_description=' . urlencode($itemDescription),
    'item_name=' . urlencode($itemName),
    'm_payment_id=' . urlencode($mPaymentId),
    'merchant-id=' . urlencode($merchantId),
    'passphrase=' . urlencode($passphrase),
    'timestamp=' . urlencode($timestamp),
    'version=' . urlencode($version),
];
$signature = md5(implode('&', $parts));

$response = \Illuminate\Support\Facades\Http::asForm()->timeout(20)->withHeaders([
    'merchant-id' => $merchantId,
    'timestamp' => $timestamp,
    'version' => $version,
    'signature' => $signature,
])->post("https://api.payfast.co.za/subscriptions/{$token}/adhoc?testing=true", [
    'amount' => $amount,
    'item_name' => $itemName,
    'item_description' => $itemDescription,
    'm_payment_id' => $mPaymentId,
]);

echo "Status: " . $response->status() . "\n";
echo "Body: " . $response->body() . "\n";
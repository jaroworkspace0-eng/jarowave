<?php

return [
    'merchant_id'  => env('PAYFAST_MERCHANT_ID'),
    'merchant_key' => env('PAYFAST_MERCHANT_KEY'),
    'passphrase'   => env('PAYFAST_PASSPHRASE'),
    'notify_url'   => env('PAYFAST_NOTIFY_URL', 'https://admin.jaroworkspace.com/api/webhooks/payfast'),
    'return_url'   => env('PAYFAST_RETURN_URL', 'https://account.jaroworkspace.com/dashboard.html'),
    'cancel_url'   => env('PAYFAST_CANCEL_URL', 'https://account.jaroworkspace.com/register.html'),
];

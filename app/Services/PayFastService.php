<?php

namespace App\Services;

class PayFastService
{
    private string $merchantId;
    private string $merchantKey;
    private string $passphrase;
    private string $baseUrl = 'https://www.payfast.co.za/eng/process';

    public function __construct()
    {
        $this->merchantId  = config('payfast.merchant_id');
        $this->merchantKey = config('payfast.merchant_key');
        $this->passphrase  = config('payfast.passphrase');
    }

    /**
     * Generate a PayFast subscription payment URL for a household trial.
     * Initial amount = 0.00 (free trial), recurring = R80/month after 30 days.
     */
    public function buildSubscriptionUrl(array $params): string
    {
        $data = array_merge([
            'merchant_id'         => $this->merchantId,
            'merchant_key'        => $this->merchantKey,
            'return_url'          => config('payfast.return_url'),
            'cancel_url'          => config('payfast.cancel_url'),
            'notify_url'          => config('payfast.notify_url'),

            // Subscription settings
            'subscription_type'   => '1',                          // recurring subscription
            'billing_date'        => now()->addDays(30)->format('Y-m-d'), // first charge after trial
            'recurring_amount'    => '80.00',                      // R80 every cycle
            'frequency'           => '3',                          // monthly
            'cycles'              => '0',                          // 0 = unlimited

            // Initial payment during trial = R0
            'amount'              => '0.00',
        ], $params);

        // Remove empty values
        $data = array_filter($data, fn($v) => $v !== '' && $v !== null);

        // Generate signature
        $data['signature'] = $this->generateSignature($data);

        return $this->baseUrl . '?' . http_build_query($data);
    }

    /**
     * Generate PayFast MD5 signature.
     */
    public function generateSignature(array $data, bool $includePassphrase = true): string
    {
        // Remove signature if present
        unset($data['signature']);

        // Build query string
        $queryString = http_build_query($data);

        if ($includePassphrase && $this->passphrase) {
            $queryString .= '&passphrase=' . urlencode($this->passphrase);
        }

        return md5($queryString);
    }

    /**
     * Verify ITN signature from PayFast webhook.
     */
    public function verifySignature(array $data): bool
    {
        $receivedSignature = $data['signature'] ?? '';
        unset($data['signature']);

        $expectedSignature = $this->generateSignature($data);

        return hash_equals($expectedSignature, $receivedSignature);
    }

    /**
     * Verify the ITN came from a valid PayFast IP.
     */
    public function isValidIp(string $ip): bool
    {
        $validIps = [
            '197.97.145.144',
            '197.97.145.145',
            '197.97.145.146',
            '197.97.145.147',
            '41.74.179.194',
            '41.74.179.195',
            '41.74.179.196',
            '41.74.179.197',
        ];

        return in_array($ip, $validIps);
    }

    /**
     * Verify ITN by sending the data back to PayFast for validation.
     */
    public function verifyItn(array $data): bool
    {
        $response = file_get_contents(
            'https://www.payfast.co.za/eng/query/validate',
            false,
            stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => http_build_query($data),
                ],
            ])
        );

        return $response === 'VALID';
    }
}

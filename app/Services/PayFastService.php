<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

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
     * Initial amount = 0.00 (free trial), recurring = R80/month after 14 days.
     */
   
    public function buildSubscriptionUrl(array $params): string
    {
        // Build in PayFast's expected order
        $data = [
            'merchant_id'       => $this->merchantId,
            'merchant_key'      => $this->merchantKey,
            'return_url'        => config('payfast.return_url'),
            'cancel_url'        => config('payfast.cancel_url'),
            'notify_url'        => config('payfast.notify_url'),
            // buyer details come here
            'name_first'        => $params['name_first'] ?? '',
            'name_last'         => $params['name_last'] ?? '',
            'email_address'     => $params['email_address'] ?? '',
            'cell_number'       => $params['cell_number'] ?? '',
            // transaction details
            'm_payment_id'      => $params['m_payment_id'] ?? '',
            'item_name'         => $params['item_name'] ?? '',
            'item_description'  => $params['item_description'] ?? '',
            'amount'            => '0.00',
            // subscription fields last
            'subscription_type' => '1',
            'billing_date'      => $params['billing_date'],
            'recurring_amount'  => '80.00',
            'frequency'         => '3',
            'cycles'            => '0',
        ];

        $data = array_filter($data, fn($v) => $v !== '' && $v !== null);

        $data['signature'] = $this->generateSignature($data);

        \Log::debug('PayFast payload: ', $data);

        return $this->baseUrl . '?' . http_build_query($data, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Generate PayFast MD5 signature.
     */
    public function generateSignature(array $data, bool $includePassphrase = true): string
    {
        unset($data['signature']);

        $queryString = http_build_query($data, '', '&', PHP_QUERY_RFC3986); // was: http_build_query($data)

        if ($includePassphrase && $this->passphrase) {
            $queryString .= '&passphrase=' . rawurlencode($this->passphrase); // was: urlencode()
        }

        \Log::debug('PayFast signature string: ' . $queryString);
        \Log::debug('PayFast signature hash: ' . md5($queryString));

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

    public function cancelSubscription(string $token): bool
    {
        $response = Http::withBasicAuth(
            config('payfast.merchant_id'),
            config('payfast.merchant_key'),
        )->put("https://api.payfast.co.za/subscriptions/{$token}/cancel", [
            'version'     => 'v1',
            'merchant-id' => config('payfast.merchant_id'),
            'timestamp'   => now()->toIso8601String(),
        ]);

        return $response->successful();
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayFastService
{
    private string $merchantId;
    private string $merchantKey;
    private string $passphrase;
    private string $baseUrl = 'https://www.payfast.co.za/eng/process';

    // PayFast-mandated field order for signature generation
    private array $fieldOrder = [
        'merchant_id', 'merchant_key', 'return_url', 'cancel_url', 'notify_url',
        'name_first', 'name_last', 'email_address', 'cell_number',
        'm_payment_id', 'amount', 'item_name', 'item_description',
        'custom_int1', 'custom_int2', 'custom_int3', 'custom_int4', 'custom_int5',
        'custom_str1', 'custom_str2', 'custom_str3', 'custom_str4', 'custom_str5',
        'email_confirmation', 'confirmation_address', 'payment_method',
        'subscription_type', 'billing_date', 'recurring_amount', 'frequency', 'cycles',
    ];

    public function __construct()
    {
        $this->merchantId  = config('payfast.merchant_id');
        $this->merchantKey = config('payfast.merchant_key');
        $this->passphrase  = config('payfast.passphrase');
    }

    public function buildSubscriptionUrl(array $params): string
    {
        $data = $this->basePayload($params, '0.00');
        $data = array_filter($data, fn($v) => $v !== '' && $v !== null);
        $data['signature'] = $this->generateSignature($data);

        Log::debug('PayFast payload: ', $data);

        return $this->baseUrl . '?' . http_build_query($data);
    }

    public function buildSubscriptionForm(array $params): string
    {
        $data = $this->basePayload($params, '0.00');
        $data = array_filter($data, fn($v) => $v !== '' && $v !== null);
        $data['signature'] = $this->generateSignature($data);

        $inputs = '';
        foreach ($data as $key => $value) {
            $inputs .= '<input type="hidden" name="' . $key . '" value="' . htmlspecialchars($value) . '">';
        }

        return '
            <form id="payfast-form" method="POST" action="' . $this->baseUrl . '">
                ' . $inputs . '
            </form>
            <script>document.getElementById("payfast-form").submit();</script>
        ';
    }

    public function buildSubscriptionFields(array $params): array
    {
        $data = $this->basePayload($params, '0.00');
        $data = array_filter($data, fn($v) => $v !== '' && $v !== null);
        $data['signature'] = $this->generateSignature($data);

        Log::debug('PayFast signature: ' . $data['signature']);

        return $data;
    }

    private function basePayload(array $params, string $amount): array
    {
        return [
            'merchant_id'      => $this->merchantId,
            'merchant_key'     => $this->merchantKey,
            'return_url'       => config('payfast.return_url'),
            'cancel_url'       => config('payfast.cancel_url'),
            'notify_url'       => config('payfast.notify_url'),
            'name_first'       => $params['name_first'] ?? '',
            'name_last'        => $params['name_last'] ?? '',
            'email_address'    => $params['email_address'] ?? '',
            'cell_number'      => $params['cell_number'] ?? '',
            'm_payment_id'     => $params['m_payment_id'] ?? '',
            'amount'           => $amount,
            'item_name'        => $params['item_name'] ?? '',
            'item_description' => $params['item_description'] ?? '',
            'subscription_type' => '1',
            'billing_date'     => $params['billing_date'],
            'recurring_amount' => '80.00',
            'frequency'        => '3',
            'cycles'           => '0',
        ];
    }

    public function generateSignature(array $data, bool $includePassphrase = true): string
    {
        unset($data['signature']);

        // Sort by PayFast-mandated field order
        $sorted = [];
        foreach ($this->fieldOrder as $key) {
            if (array_key_exists($key, $data)) {
                $sorted[$key] = $data[$key];
            }
        }

        $parts = [];
        foreach ($sorted as $key => $value) {
            $parts[] = $key . '=' . rawurlencode(trim((string) $value));
        }

        $queryString = implode('&', $parts);

        if ($includePassphrase && $this->passphrase) {
            $queryString .= '&passphrase=' . rawurlencode(trim($this->passphrase));
        }

        Log::debug('PayFast signature string: ' . $queryString);

        return md5($queryString);
    }

    public function verifySignature(array $data): bool
    {
        $receivedSignature = $data['signature'] ?? '';
        unset($data['signature']);

        $expectedSignature = $this->generateSignature($data);

        return hash_equals($expectedSignature, $receivedSignature);
    }

    public function isValidIp(string $ip): bool
    {
        $validIps = [
            '197.97.145.144', '197.97.145.145', '197.97.145.146', '197.97.145.147',
            '41.74.179.194',  '41.74.179.195',  '41.74.179.196',  '41.74.179.197',
        ];

        return in_array($ip, $validIps);
    }

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
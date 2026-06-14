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
        // ITN response fields
        'pf_payment_id', 'payment_status', 'item_name', 'item_description',
        'amount_gross', 'amount_fee', 'amount_net', 'token',
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

    public function buildSubscriptionFields(array $params, string $amount = '0.00'): array
    {
        $data = $this->basePayload($params, $amount);
        $data = array_filter($data, fn($v) => $v !== '' && $v !== null);
        $data['signature'] = $this->generateSignature($data);

        Log::debug('PayFast signature: ' . $data['signature']);

        return $data;
    }

    private function basePayload(array $params, string $amount): array
    {
        return [
            'merchant_id'       => $this->merchantId,
            'merchant_key'      => $this->merchantKey,
            'return_url'        => config('payfast.return_url'),
            'cancel_url'        => config('payfast.cancel_url'),
            'notify_url'        => config('payfast.notify_url'),
            'name_first'        => $params['name_first'] ?? '',
            'name_last'         => $params['name_last'] ?? '',
            'email_address'     => $params['email_address'] ?? '',
            'cell_number'       => $params['cell_number'] ?? '',
            'm_payment_id'      => $params['m_payment_id'] ?? '',
            'amount'            => $amount,
            'item_name'         => $params['item_name'] ?? '',
            'item_description'  => $params['item_description'] ?? '',
            'custom_str1'       => $params['custom_str1'] ?? '',
            'subscription_type' => '1',
            'billing_date'      => $params['billing_date'],
            // 'recurring_amount'  => number_format(BillingService::UNIT_PRICE / 100, 2, '.', ''),
            'recurring_amount' => number_format(BillingService::unitPrice($params['amount_per_household'] ?? null), 2, '.', ''),
            'frequency'         => '3',
            'cycles'            => '0',
        ];
    }

    public function generateSignature(array $data, bool $includePassphrase = true): string
    {
        unset($data['signature']);

        $sorted = [];
        foreach ($this->fieldOrder as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                $sorted[$key] = $data[$key];
            }
        }

        $parts = [];
        foreach ($sorted as $key => $value) {
            $parts[] = $key . '=' . urlencode(trim((string) $value));
        }

        $queryString = implode('&', $parts);

        if ($includePassphrase && $this->passphrase) {
            $queryString .= '&passphrase=' . urlencode(trim($this->passphrase));
        }


        Log::debug('PayFast signature string: ' . urldecode($queryString)); // decode for readability only
        Log::debug('PayFast signature string raw: ' . $queryString);

        return md5($queryString);
    }

    public function verifySignature(array $data): bool
    {
        $receivedSignature = $data['signature'] ?? '';
        unset($data['signature']);

        // Keep field order from PayFast, but convert nulls to empty strings
        // Only exclude fields that are truly absent, not just null
        $parts = [];
        foreach ($data as $key => $value) {
            $parts[] = $key . '=' . urlencode(trim((string) ($value ?? '')));
        }

        $queryString = implode('&', $parts);
        $queryString .= '&passphrase=' . urlencode(trim($this->passphrase));

        Log::debug('PayFast ITN signature string raw: ' . $queryString);

        return hash_equals(md5($queryString), $receivedSignature);
    }
    public function isValidIp(string $ip): bool
{
    $validRanges = [
        '3.163.232.0/21',       // PayFast primary AWS (Cape Town)
        '197.97.145.144/28',    // Legacy
        '41.74.179.192/27',     // Legacy
        '102.216.36.0/28',      // Legacy
        '102.216.36.128/28',    // Legacy
        '144.126.193.139/32',   // Legacy single IP
    ];

    foreach ($validRanges as $range) {
        if ($this->ipInCidr($ip, $range)) {
            return true;
        }
    }

    return false;
}

private function ipInCidr(string $ip, string $cidr): bool
{
    [$subnet, $bits] = explode('/', $cidr);
    $mask = ~((1 << (32 - (int)$bits)) - 1);

    return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
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
        $timestamp = now()->toIso8601String();
        $version   = 'v1';

        $parts = [
            'merchant-id=' . urlencode($this->merchantId),
            'passphrase='  . urlencode($this->passphrase),
            'timestamp='   . urlencode($timestamp),
            'version='     . urlencode($version),
        ];
        $signature = md5(implode('&', $parts));

        $response = Http::withHeaders([
            'merchant-id' => $this->merchantId,
            'passphrase'  => $this->passphrase,
            'timestamp'   => $timestamp,
            'version'     => $version,
            'signature'   => $signature,
        ])->put("https://api.payfast.co.za/subscriptions/{$token}/cancel");

        Log::debug('PayFast cancel response: ' . $response->status() . ' ' . $response->body());

        return $response->successful();
    }

    public function chargeAdhoc(string $token, float $amount): bool
    {
        $timestamp = now()->toIso8601String();
        $version   = 'v1';

        $parts = [
            'merchant-id=' . urlencode($this->merchantId),
            'passphrase='  . urlencode($this->passphrase),
            'timestamp='   . urlencode($timestamp),
            'version='     . urlencode($version),
        ];
        $signature = md5(implode('&', $parts));

        $response = Http::withHeaders([
            'merchant-id' => $this->merchantId,
            'passphrase'  => $this->passphrase,
            'timestamp'   => $timestamp,
            'version'     => $version,
            'signature'   => $signature,
        ])->post("https://api.payfast.co.za/subscriptions/{$token}/adhoc", [
            'amount'    => (int)($amount * 100), // in cents
            'item_name' => 'Echo Link Community Protection',
        ]);

        Log::debug('PayFast adhoc response: ' . $response->status() . ' ' . $response->body());

        return $response->successful();
    }

    public function buildOneTimeFields(array $params): array
    {
        $data = [
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
            // 'amount'           => number_format(BillingService::UNIT_PRICE / 100, 2, '.', ''),
            'amount' => number_format(BillingService::unitPrice($params['amount_per_household'] ?? null), 2, '.', ''),
            'item_name'        => $params['item_name'] ?? '',
            'item_description' => $params['item_description'] ?? '',
            'custom_str1'      => $params['custom_str1'] ?? '',
        ];

        $data = array_filter($data, fn($v) => $v !== '' && $v !== null);
        $data['signature'] = $this->generateSignature($data);

        return $data;
    }
}
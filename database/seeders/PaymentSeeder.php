<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Earning;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Grab or create an estate client ──────────────────────────

        $user = User::firstOrCreate(
            ['email' => 'estate@echolink.co.za'],
            [
                'name'     => 'Sunridge Estate',
                'phone'    => '0821234567',
                'password' => bcrypt('password'),
                'role'     => 'client',
            ]
        );

        $client = Client::firstOrCreate(['user_id' => $user->id]);

        // ── 2. Grab or create a watch group client ───────────────────────

        $watchUser = User::firstOrCreate(
            ['email' => 'watch@echolink.co.za'],
            [
                'name'     => 'Midrand North Watch',
                'phone'    => '0837654321',
                'password' => bcrypt('password'),
                'role'     => 'client',
            ]
        );

        $watchClient = Client::firstOrCreate(['user_id' => $watchUser->id]);

        // ── 3. Grab or create a resident ─────────────────────────────────

        $resident = User::firstOrCreate(
            ['email' => 'resident@echolink.co.za'],
            [
                'name'     => 'John Dlamini',
                'phone'    => '0711234567',
                'password' => bcrypt('password'),
                'role'     => 'resident',
            ]
        );

        // ── 4. Seed estate subscriptions + payments ──────────────────────

        $plans = [
            ['plan' => 'basic',    'price' => 49900,  'billing_cycle' => 'monthly', 'original' => 49900,  'discount' => 0,    'pct' => 0],
            ['plan' => 'standard', 'price' => 99900,  'billing_cycle' => 'monthly', 'original' => 99900,  'discount' => 0,    'pct' => 0],
            ['plan' => 'premium',  'price' => 199900, 'billing_cycle' => 'monthly', 'original' => 199900, 'discount' => 0,    'pct' => 0],
            ['plan' => 'standard', 'price' => 996564, 'billing_cycle' => 'annual',  'original' => 1198800,'discount' => 202236,'pct' => 17],
        ];

        foreach ($plans as $index => $p) {
            $periodStart = now()->subMonths(count($plans) - $index);
            $periodEnd   = $p['billing_cycle'] === 'annual'
                ? $periodStart->copy()->addYear()
                : $periodStart->copy()->addMonth();

            $subscription = Subscription::create([
                'client_id'            => $client->id,
                'plan'                 => $p['plan'],
                'billing_cycle'        => $p['billing_cycle'],
                'status'               => 'active',
                'price'                => $p['price'],
                'original_price'       => $p['original'],
                'discount_amount'      => $p['discount'],
                'discount_percentage'  => $p['pct'],
                'trial_ends_at'        => $periodStart->copy()->subDays(30),
                'current_period_start' => $periodStart,
                'current_period_end'   => $periodEnd,
            ]);

            $reference = 'ECL-' . strtoupper(Str::random(10));

            $payment = SubscriptionPayment::create([
                'subscription_id'           => $subscription->id,
                'gateway'                   => $index % 2 === 0 ? 'payfast' : 'ozow',
                'gateway_transaction_id'    => 'TXN-' . strtoupper(Str::random(12)),
                'gateway_payment_reference' => $reference,
                'gateway_status'            => 'COMPLETE',
                'amount'                    => $p['price'],
                'amount_gross'              => $p['price'],
                'amount_fee'                => (int) round($p['price'] * 0.035),  // 3.5% fee
                'amount_net'                => (int) round($p['price'] * 0.965),
                'currency'                  => 'ZAR',
                'payment_method'            => $index % 2 === 0 ? 'credit_card' : 'instant_eft',
                'payer_name'                => $user->name,
                'payer_email'               => $user->email,
                'status'                    => 'paid',
                'gateway_payload'           => [
                    'pf_payment_id'  => 'TXN-' . strtoupper(Str::random(12)),
                    'm_payment_id'   => $reference,
                    'payment_status' => 'COMPLETE',
                    'amount_gross'   => number_format($p['price'] / 100, 2),
                    'amount_fee'     => number_format(round($p['price'] * 0.035) / 100, 2),
                    'amount_net'     => number_format(round($p['price'] * 0.965) / 100, 2),
                ],
                'billing_period_start' => $periodStart,
                'billing_period_end'   => $periodEnd,
                'paid_at'              => $periodStart,
            ]);

            // Create invoice for each payment
            Invoice::create([
                'subscription_payment_id' => $payment->id,
                'client_id'               => $client->id,
                'invoice_number'          => Invoice::generateNumber(),
                'status'                  => 'paid',
                'subtotal'                => $p['original'],
                'discount_amount'         => $p['discount'],
                'total'                   => $p['price'],
                'currency'                => 'ZAR',
                'issued_at'               => $periodStart,
                'sent_at'                 => $periodStart->copy()->addMinutes(5),
            ]);

            $this->command->info("✓ Created {$p['plan']} {$p['billing_cycle']} payment + invoice");
        }

        // ── 5. Seed watch group resident payments + earnings ─────────────

        $watchSubscription = Subscription::create([
            'client_id'            => $watchClient->id,
            'plan'                 => null,
            'billing_cycle'        => 'monthly',
            'status'               => 'active',
            'price'                => 0,
            'original_price'       => 0,
            'discount_amount'      => 0,
            'discount_percentage'  => 0,
            'trial_ends_at'        => now()->subDays(20),
            'current_period_start' => now()->startOfMonth(),
            'current_period_end'   => now()->endOfMonth(),
        ]);

        // Simulate 3 months of resident payments
        for ($month = 3; $month >= 1; $month--) {
            $periodStart = now()->subMonths($month)->startOfMonth();
            $periodEnd   = $periodStart->copy()->endOfMonth();
            $reference   = 'ECL-' . strtoupper(Str::random(10));

            // Resident subscription
            $residentSub = Subscription::create([
                'client_id'            => $watchClient->id,
                'plan'                 => null,
                'billing_cycle'        => 'monthly',
                'status'               => 'active',
                'price'                => 8000,
                'original_price'       => 8000,
                'discount_amount'      => 0,
                'discount_percentage'         => 0,
                'trial_ends_at'        => $periodStart->copy()->subDays(14),
                'current_period_start' => $periodStart,
                'current_period_end'   => $periodEnd,
            ]);

            $residentPayment = SubscriptionPayment::create([
                'subscription_id'           => $residentSub->id,
                'gateway'                   => 'payfast',
                'gateway_transaction_id'    => 'TXN-' . strtoupper(Str::random(12)),
                'gateway_payment_reference' => $reference,
                'gateway_status'            => 'COMPLETE',
                'amount'                    => 8000,  // R80.00
                'amount_gross'              => 8000,
                'amount_fee'                => 280,   // 3.5%
                'amount_net'                => 7720,
                'currency'                  => 'ZAR',
                'payment_method'            => 'credit_card',
                'payer_name'                => $resident->name,
                'payer_email'               => $resident->email,
                'status'                    => 'paid',
                'gateway_payload'           => [
                    'payment_status' => 'COMPLETE',
                    'amount_gross'   => '80.00',
                ],
                'billing_period_start' => $periodStart,
                'billing_period_end'   => $periodEnd,
                'paid_at'              => $periodStart,
            ]);

            // Earning for watch group — 65%
            Earning::create([
                'client_id'               => $watchClient->id,
                'subscription_payment_id' => $residentPayment->id,
                'resident_id'             => $resident->id,
                'resident_amount'         => 8000,
                'commission_percentage'          => 65,
                'earned_amount'           => 520,  // R52.00
                'platform_amount'         => 280,  // R28.00
                'status'                  => $month === 1 ? 'pending' : 'paid',
                'payout_at'               => $month === 1 ? null : $periodEnd,
                'payout_reference'        => $month === 1 ? null : 'EFT-' . strtoupper(Str::random(8)),
                'period_start'            => $periodStart,
                'period_end'              => $periodEnd,
            ]);

            $this->command->info("✓ Created resident payment + earning for month -{$month}");
        }

        $this->command->info('');
        $this->command->info('🎉 Payment seeder complete!');
        $this->command->info('');
        $this->command->info('Test accounts:');
        $this->command->info('  Estate client  → estate@echolink.co.za / password');
        $this->command->info('  Watch group    → watch@echolink.co.za  / password');
        $this->command->info('  Resident       → resident@echolink.co.za / password');
    }
}
<?php

namespace App\Console\Commands;

use App\Models\Earning;
use Illuminate\Console\Command;

// php artisan earnings:clean-mismatched --dry-run   (preview only, no changes)
// php artisan earnings:clean-mismatched              (deletes after confirmation)
class CleanOrphanedEarnings extends Command
{
    protected $signature = 'earnings:clean-mismatched {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Find and remove Earning rows whose resident_amount does not match their linked SubscriptionPayment amount';

    public function handle(): int
    {
        $mismatched = Earning::whereHas('payment', function ($q) {
            $q->whereColumn('subscription_payments.amount', '!=', 'earnings.resident_amount');
        })->with('payment', 'client')->get();

        if ($mismatched->isEmpty()) {
            $this->info('No mismatched earnings found.');
            return self::SUCCESS;
        }

        $this->warn("Found {$mismatched->count()} mismatched earning(s):");
        $this->table(
            ['Earning ID', 'Client', 'Status', 'Earning resident_amount', 'Payment amount', 'Payment ID'],
            $mismatched->map(fn ($e) => [
                $e->id,
                $e->client?->organisation_name ?? $e->client_id,
                $e->status,
                $e->resident_amount,
                $e->payment->amount,
                $e->payment->id,
            ])
        );

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing deleted.');
            return self::SUCCESS;
        }

        if (!$this->confirm('Delete these ' . $mismatched->count() . ' earning(s)? This cannot be undone.')) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        $paidCount = $mismatched->where('status', 'paid')->count();
        if ($paidCount > 0) {
            $this->error("{$paidCount} of these are already marked 'paid' — refusing to auto-delete paid records. Review manually.");
            return self::FAILURE;
        }

        foreach ($mismatched as $earning) {
            \Illuminate\Support\Facades\Log::info('Deleting mismatched earning', [
                'earning_id' => $earning->id,
                'client_id'  => $earning->client_id,
                'resident_amount_was' => $earning->resident_amount,
                'payment_amount' => $earning->payment->amount,
            ]);
            $earning->delete();
        }

        $this->info("Deleted {$mismatched->count()} earning(s).");
        return self::SUCCESS;
    }
}
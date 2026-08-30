<?php

namespace App\Console\Commands;

use App\Mail\EstateMonthlyReportMail;
use App\Models\Channel;
use App\Services\EstateAnalyticsService;
use Barryvdh\DomPDF\Facade\Pdf; // ASSUMPTION: swap for whatever lib generates your invoice PDFs if different
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * php artisan estates:send-monthly-reports
 * Scheduled dailyAt/monthlyOn(1) in app/Console/Kernel.php, same pattern
 * as SendPaymentReminders.
 */
class SendEstateMonthlyReports extends Command
{
    protected $signature = 'estates:send-monthly-reports';
    protected $description = 'Generate and email the previous month\'s analytics PDF report to each active estate.';

    public function handle(EstateAnalyticsService $analytics)
    {
        $from = now()->subMonthNoOverflow()->startOfMonth();
        $to = now()->subMonthNoOverflow()->endOfMonth();

        $channels = Channel::where('billing_model', 'bulk')
            ->where('status', 'active')
            ->get();

        foreach ($channels as $channel) {
            $data = $analytics->summary($channel->id, $from, $to);

            $pdf = Pdf::loadView('reports.estate-monthly', [
                'channel' => $channel,
                'data' => $data,
                'periodLabel' => $from->format('F Y'),
            ]);

            $filename = "estate-report-{$channel->id}-{$from->format('Y-m')}.pdf";
            $path = "estate-reports/{$filename}";
            Storage::put($path, $pdf->output());

            $recipients = \DB::table('channel_billing_contacts')
                ->join('users', 'users.id', '=', 'channel_billing_contacts.user_id')
                ->where('channel_billing_contacts.channel_id', $channel->id)
                ->where('channel_billing_contacts.is_active', true)
                ->pluck('users.email');

            foreach ($recipients as $email) {
                Mail::to($email)->queue(
                    new EstateMonthlyReportMail($channel, $data, $from, Storage::path($path))
                );
            }

            $this->info("Sent report for channel {$channel->id} to " . $recipients->count() . ' recipient(s).');
        }
    }
}
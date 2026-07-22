<?php

namespace App\Console\Commands;

use App\Models\AccountLink;
use Illuminate\Console\Command;

class EscalateStaleAccountLinks extends Command
{
    protected $signature = 'account-links:escalate';
    protected $description = 'Escalate account-link requests pending longer than 48 hours to Echo Link admin';

    public function handle(): int
    {
        $stale = AccountLink::where('status', 'pending')
            ->where('escalated', false)
            ->where('created_at', '<=', now()->subHours(48))
            ->get();

        foreach ($stale as $link) {
            $link->update([
                'escalated'    => true,
                'escalated_at' => now(),
            ]);

            // TODO: notify Echo Link admin dashboard (socket emit / FCM /
            // whatever your admin alert channel already uses — same
            // notifyAdmins() pipeline as emergency alerts would fit here)
        }

        $this->info("Escalated {$stale->count()} stale account link(s).");
        return self::SUCCESS;
    }
}
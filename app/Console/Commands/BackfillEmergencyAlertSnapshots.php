<?php

namespace App\Console\Commands;

use App\Models\EmergencyAlert;
use Illuminate\Console\Command;

class BackfillEmergencyAlertSnapshots extends Command
{
    protected $signature = 'alerts:backfill-snapshots {--chunk=200}';
    protected $description = 'Backfill name/phone/address snapshot fields on existing emergency_alerts rows from their related User';

    public function handle()
    {
        $chunkSize = (int) $this->option('chunk');
        $updated = 0;
        $skipped = 0;

        EmergencyAlert::withTrashed()
            ->whereNull('email') // only rows not yet backfilled
            ->with(['user' => fn ($q) => $q->withTrashed()])
            ->chunkById($chunkSize, function ($alerts) use (&$updated, &$skipped) {
                foreach ($alerts as $alert) {
                    $user = $alert->user;

                    if (!$user) {
                        $skipped++;
                        continue;
                    }

                    $alert->update([
                        'name' => $user->name,
                        'phone' => $user->phone,
                        'email' => $user->email,
                        'address_line_1' => $user->address_line_1,
                        'complex_name' => $user->complex_name,
                        'suburb' => $user->suburb,
                        'unit_number' => $user->unit_number,
                        'alert_location_source' => $user->alert_location_source,
                        'is_estate' => $user->is_estate,
                    ]);

                    $updated++;
                }
            });

        $this->info("Backfilled {$updated} alerts. Skipped {$updated} with no related user.");
        $this->info("Skipped {$skipped} alerts with no related user (already anonymized/deleted).");

        return self::SUCCESS;
    }
}
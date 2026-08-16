<?php

namespace App\Console\Commands;

use App\Models\AccountLink;
use App\Models\Employee;
use App\Models\TenantAddressHistory;
use Illuminate\Console\Command;

class BackfillTenantAddressHistory extends Command
{
    protected $signature = 'tenants:backfill-address-history {--dry-run : Show what would be created without writing anything}';

    protected $description = 'Create an initial TenantAddressHistory row for existing tenants that predate address history tracking';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $employees = Employee::with(['user', 'channels'])
            ->whereHas('user', fn ($q) => $q->whereIn('occupation', ['household', 'resident']))
            ->whereDoesntHave('user.addressHistory')
            ->get();

        $this->info("Found {$employees->count()} tenant(s) with no address history.");

        $created = 0;
        $skipped = 0;

        foreach ($employees as $employee) {
            $user = $employee->user;
            $channel = $employee->channels->first();

            if (!$user || !$channel) {
                $this->warn("Skipping employee #{$employee->id} — missing user or channel.");
                $skipped++;
                continue;
            }

            // Was this address inherited via an approved account link, rather
            // than being the tenant's own? Look up the active link where this
            // user is the linked (dependent) side.
            $accountLink = AccountLink::where('linked_account_id', $user->id)
                ->where('status', 'active')
                ->first();

            $sourceUserId = $accountLink?->primary_account_id;
            $isInherited  = (bool) $sourceUserId;

            if ($dryRun) {
                $inheritedNote = $isInherited ? " (inherited from user #{$sourceUserId})" : '';
                $this->line("Would create history for user #{$user->id} ({$user->name}) — channel #{$channel->id} ({$channel->name}), effective_from {$user->created_at}{$inheritedNote}");
                $created++;
                continue;
            }

            TenantAddressHistory::create([
                'user_id'         => $user->id,
                'channel_id'      => $channel->id,
                'is_inherited'    => $isInherited,
                'source_user_id'  => $sourceUserId,
                'address_line_1'  => $user->address_line_1,
                'suburb'          => $user->suburb,
                'complex_name'    => $user->complex_name,
                'unit_number'     => $user->unit_number,
                'latitude'        => $user->latitude,
                'longitude'       => $user->longitude,
                'effective_from'  => $user->created_at,
                'effective_to'    => null,
            ]);

            $created++;
        }

        $label = $dryRun ? 'Would create' : 'Created';
        $this->info("{$label}: {$created}. Skipped: {$skipped}.");

        return self::SUCCESS;
    }
}
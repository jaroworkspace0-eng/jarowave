<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\TenantAddressHistory;
use App\Models\User;

class AddressHistoryService
{
    public function record(User $user, Channel $channel, ?User $source = null): void
    {
        TenantAddressHistory::where('user_id', $user->id)
            ->whereNull('effective_to')
            ->update(['effective_to' => now()]);

        TenantAddressHistory::create([
            'user_id'         => $user->id,
            'channel_id'      => $channel->id,
            'is_inherited'    => (bool) $source,
            'source_user_id'  => $source?->id,
            'address_line_1'  => $user->address_line_1,
            'suburb'          => $user->suburb,
            'complex_name'    => $user->complex_name,
            'unit_number'     => $user->unit_number,
            'latitude'        => $user->latitude,
            'longitude'       => $user->longitude,
            'effective_from'  => now(),
        ]);
    }

    public function close(User $user): void
    {
        TenantAddressHistory::where('user_id', $user->id)
            ->whereNull('effective_to')
            ->update(['effective_to' => now()]);
    }
}
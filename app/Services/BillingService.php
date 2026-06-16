<?php

namespace App\Services;

use App\Models\Channel;

class BillingService
{
    // Total monthly fee per household unit — R80
    const UNIT_PRICE = 8000; // in cents

    // Split by organisation type
    const SPLITS = [
        'watch'  => ['client' => 5200, 'platform' => 2800], // R52 / R28
        'estate' => ['client' => 5200, 'platform' => 2800], // R52 / R28
    ];

    /**
     * Get the split amounts for a given organisation type.
     * Returns amounts in cents.
     */
    public static function getSplit(string $orgType): array
    {
        return self::SPLITS[$orgType] ?? self::SPLITS['watch'];
    }

    /**
     * Get client share in cents.
     */
    public static function clientShare(string $orgType): int
    {
        return self::getSplit($orgType)['client'];
    }

    /**
     * Get platform share in cents.
     */
    public static function platformShare(string $orgType): int
    {
        return self::getSplit($orgType)['platform'];
    }

    /**
     * Get formatted amounts for display (in Rands).
     */
    public static function getDisplayAmounts(string $orgType): array
    {
        $split = self::getSplit($orgType);
        return [
            'total'    => self::UNIT_PRICE / 100,           // R80
            'client'   => $split['client'] / 100,           // R52 or R30
            'platform' => $split['platform'] / 100,         // R28 or R50
        ];
    }

    /**
     * Get the amount per household for a channel.
     * Falls back to UNIT_PRICE if not set on the channel.
     */
    public static function unitPrice(?float $amountPerHousehold = null): float
    {
        return $amountPerHousehold ?? self::UNIT_PRICE / 100;
    }

    /**
     * Resolve the effective security percentage for a channel.
     * Falls back to the client's revenue_share_percentage if not set on the channel.
     */
    public static function resolveSecurityPercentage(Channel $channel): float
    {
        return $channel->security_percentage ?? $channel->client?->revenue_share_percentage ?? 0;
    }

    /**
     * Resolve the security pool for a channel.
     * Falls back to (amount_per_household - guard_fixed_amount) if not set.
     */
    public static function resolveSecurityPool(Channel $channel): float
    {
        if ($channel->security_pool !== null) {
            return (float) $channel->security_pool;
        }

        return (float) $channel->amount_per_household - (float) ($channel->guard_fixed_amount ?? 0);
    }

    /**
     * Calculate the full billing breakdown for a channel given a household count.
     * Returns all split amounts in Rands.
     */
    public static function calculateChannelSplit(Channel $channel, int $householdCount): array
    {
        $amountPerHousehold = (float) $channel->amount_per_household;
        $guardFixedAmount   = (float) ($channel->guard_fixed_amount ?? 0);
        $securityPool       = self::resolveSecurityPool($channel);
        $securityPercentage = self::resolveSecurityPercentage($channel);

        $totalCollected   = $amountPerHousehold * $householdCount;
        $guardPoolTotal    = $guardFixedAmount * $householdCount;
        $securityPoolTotal = $securityPool * $householdCount;
        $securityPayout    = $securityPoolTotal * ($securityPercentage / 100);
        $echoLinkPayout    = $totalCollected - $guardPoolTotal - $securityPayout;

        return [
            'total_collected'      => $totalCollected,
            'guard_pool_total'     => $guardPoolTotal,
            'security_pool_total'  => $securityPoolTotal,
            'security_percentage'  => $securityPercentage,
            'security_payout'      => $securityPayout,
            'echo_link_payout'     => $echoLinkPayout,
        ];
    }
}
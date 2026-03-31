<?php

namespace App\Services;

class BillingService
{
    // Total monthly fee per household unit — R80
    const UNIT_PRICE = 8000; // in cents

    // Split by organisation type
    const SPLITS = [
        'watch'  => ['client' => 5200, 'platform' => 2800], // R52 / R28
        'estate' => ['client' => 3000, 'platform' => 5000], // R30 / R50
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
}

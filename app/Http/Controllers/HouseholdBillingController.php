<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AccountLink;
use App\Services\ChannelBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * ══════════════════════════════════════════════════════════
 * ASSUMPTIONS — adjust to match your actual schema/service:
 *
 * - Households are Users (users table) — auth()->user() IS
 *   the household account directly, no separate Household model.
 * - AccountLink has: primary_account_id, linked_account_id, status
 *   ('active'), matching the model already used by LinkAccountTab.
 * - ChannelBillingService::nextInvoiceFor(User $household) — see
 *   the addition in channel-billing-service-addition.php — handles
 *   both standalone (own Subscription/PayFast) and estate-opted-in
 *   billing, and returns the breakdown directly.
 * ══════════════════════════════════════════════════════════
 */
class HouseholdBillingController extends Controller
{
    public function __construct(private ChannelBillingService $billing)
    {
    }

    /**
     * GET /api/household/billing/next
     */
    public function next(Request $request)
    {
        $household = $request->user();

        if (!$household) {
            return response()->json(['message' => 'Household not found'], 404);
        }

        $isPrimary = $this->isPrimaryAccount($household);

        // Non-primary: return the primary account's name so the
        // app can show "covered under X's household" — no financial
        // figures for a linked account.
        if (!$isPrimary) {
            $link = AccountLink::where('linked_account_id', $household->id)
                ->where('status', 'active')
                ->with('primaryAccount')
                ->first();

            return response()->json([
                'is_primary' => false,
                'primary_account' => $link?->primaryAccount ? [
                    'name' => $link->primaryAccount->name,
                ] : null,
            ]);
        }

        $invoice = $this->billing->nextInvoiceFor($household);

        return response()->json([
            'is_primary'   => true,
            'amount'       => $invoice['amount'],
            'due_date'     => $invoice['due_date'],
            'status'       => $invoice['status'], // active | trialing | past_due | cancelled | inactive
            'billing_mode' => $invoice['billing_mode'], // 'estate' | 'standalone'
            'breakdown'    => $invoice['breakdown'],
        ]);
    }

    /**
     * GET /api/household/billing/manage-link
     *
     * Mints a short-lived handshake code (same pattern as the
     * admin dashboard's `dashboard-handshake:{code}` cache entry)
     * so the web dashboard can authenticate the household without
     * a separate login. The web billing page must validate this
     * code server-side and start a session for that household —
     * mirror however /live-alerts validates dashboard-handshake.
     */
    public function manageLink(Request $request)
    {
        $household = $request->user();

        if (!$household) {
            return response()->json(['message' => 'Household not found'], 404);
        }

        $code = Str::random(40);
        Cache::put("household-billing-handshake:{$code}", $household->id, now()->addMinutes(10));

        $url = config('app.dashboard_url', 'https://admin.jaroworkspace.com')
            . '/household/billing?handshake=' . $code;

        return response()->json(['url' => $url]);
    }

    private function isPrimaryAccount(User $household): bool
    {
        // TODO: adjust if "primary" is tracked via a column instead
        // (e.g. $household->is_primary) rather than derived from
        // absence of an active outbound AccountLink.
        return !AccountLink::where('linked_account_id', $household->id)
            ->where('status', 'active')
            ->exists();
    }
}
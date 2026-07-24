<?php

namespace App\Http\Controllers;

use App\Mail\AccountLinkApprovedPrimaryMail;
use App\Mail\AccountLinkedMail;
use App\Mail\AccountLinkRejectedMail;
use App\Models\AccountLink;
use App\Models\User;
use App\Services\BillingService;
use App\Services\PayFastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AccountLinkController extends Controller
{

    // ── New private helper — add anywhere in the class ──
    // Recalculates a standalone (non-estate) primary's subscription price
    // from their base rate + active linked accounts, and pushes the new
    // amount to PayFast if they're on an active tokenized subscription.
    // No-ops silently for estate-billed households (ChannelBillingService
    // already recalculates those every cycle) and for anyone without an
    // active PayFast token.
    private function syncStandaloneSubscriptionAmount(User $primary): ?float
    {
        $subscription = $primary->subscription;
    
        if (
            ! $subscription
            || ! in_array($subscription->status, ['active', 'trialing', 'past_due'])
            || ! $subscription->payfast_token
        ) {
            return null; // not on a standalone tokenized subscription — estate-billed or inactive
        }
    
        $channel = $primary->employee?->channels->first();
        if (! $channel) {
            return null;
        }
    
        $basePrice  = BillingService::unitPrice($channel->amount_per_household);
        $linkedRate = BillingService::unitPrice($channel->amount_per_linked_account);
    
        $activeLinkedCount = AccountLink::where('primary_account_id', $primary->id)
            ->where('status', 'active')
            ->count();
    
        $newPrice = $basePrice + ($activeLinkedCount * $linkedRate);
    
        if ((float) $subscription->price === $newPrice) {
            return $newPrice; // already correct — still return it for the email
        }
    
        $subscription->update(['price' => $newPrice]);
    
        try {
            $updated = app(PayFastService::class)->updateSubscriptionAmount($subscription->payfast_token, $newPrice);
    
            if (! $updated) {
                Log::warning('PayFast subscription amount update returned unsuccessful', [
                    'user_id'         => $primary->id,
                    'subscription_id' => $subscription->id,
                    'new_price'       => $newPrice,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('PayFast subscription amount update failed', [
                'user_id'         => $primary->id,
                'subscription_id' => $subscription->id,
                'new_price'       => $newPrice,
                'error'           => $e->getMessage(),
            ]);
        }
    
        return $newPrice;
    }
    


    // ── Is the current user eligible to be a "primary" and link others? ──
    // A user is NOT eligible if they themselves are currently linked
    // (pending or active) to someone else as a child account.
    private function assertCanLink(int $userId): void
    {
        $isLinkedAsChild = AccountLink::where('linked_account_id', $userId)
            ->whereIn('status', ['pending', 'active'])
            ->exists();

        if ($isLinkedAsChild) {
            abort(403, 'Linked accounts cannot link other accounts.');
        }
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = AccountLink::query()
            ->with([
                'primaryAccount:id,name,phone',
                'primaryAccount.employee.channels:id,name,billing_model',
                'linkedAccount:id,name,phone,address_line_1,complex_name,suburb,unit_number',
            ])
            ->orderByDesc('created_at');

        if ($user->role === 'admin') {
            // sees every request, no filter
        } elseif ($user->role === 'estate_billing') {
            $channelIds = $user->accessibleChannelIds();

            $query->whereHas('primaryAccount.employee.channels', function ($q) use ($channelIds) {
                $q->whereIn('channels.id', $channelIds);
            });
        } else {
            // household — either their own sent requests (as primary)
            // or the request where they ARE the linked account
            $query->where(function ($q) use ($user) {
                $q->where('primary_account_id', $user->id)
                ->orWhere('linked_account_id', $user->id);
            });
        }

        $links = $query->get()->map(function (AccountLink $l) use ($user) {
            $row = [
                'id'                 => $l->id,
                'status'             => $l->status,
                'escalated'          => $l->escalated,
                'created_at'         => $l->created_at,
                'approved_at'        => $l->approved_at,
                'primary_account_id' => $l->primary_account_id,
                'linked_account_id'  => $l->linked_account_id,
                'linked_account'     => $l->linkedAccount,
            ];

            if (in_array($user->role, ['admin', 'estate_billing']) || $l->linked_account_id === $user->id) {
                $row['primary_account'] = $l->primaryAccount;
            }

            if (in_array($user->role, ['admin', 'estate_billing'])) {
                $primaryChannel = $l->primaryAccount?->employee?->channels->first();
                $row['channel'] = $primaryChannel ? [
                    'id'   => $primaryChannel->id,
                    'name' => $primaryChannel->name,
                    'type' => $primaryChannel->billing_model === 'bulk' ? 'estate' : 'standalone',
                ] : null;
            }

            return $row;
        });

        return response()->json($links);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'linked_account_ids'   => 'required|array|min:1',
            'linked_account_ids.*' => 'integer|exists:users,id',
        ]);

        $primaryId = $request->user()->id;
        $this->assertCanLink($primaryId);

        $created = [];
        $skipped = [];

        foreach ($request->linked_account_ids as $targetId) {
            $targetId = (int) $targetId;

            if ($targetId === $primaryId) {
                $skipped[] = $targetId;
                continue;
            }

            // Target must not currently be linked (pending/active) to ANYONE
            $alreadyLinked = AccountLink::where('linked_account_id', $targetId)
                ->whereIn('status', ['pending', 'active'])
                ->exists();

            if ($alreadyLinked) {
                // $skipped[] = $targetId;
                $skipped[] = ['id' => $targetId, 'reason' => 'already_linked'];
                continue;
            }

            // Target must not itself already be a primary with active/pending
            // links of their own — prevents chaining (a "primary" being linked
            // as someone else's child while they still have their own children).
            $targetIsPrimaryElsewhere = AccountLink::where('primary_account_id', $targetId)
                ->whereIn('status', ['pending', 'active'])
                ->exists();

            if ($targetIsPrimaryElsewhere) {
                $skipped[] = $targetId;
                continue;
            }

            $created[] = AccountLink::create([
                'primary_account_id' => $primaryId,
                'linked_account_id'  => $targetId,
                'status'             => 'pending',
            ]);
        }

        return response()->json([
            'created' => $created,
            'skipped' => $skipped,
        ], 201);
    }

    // Cancel a pending request (primary-initiated)
    public function destroy(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;
    
        $link = AccountLink::with('primaryAccount')->where('id', $id) // eager-load primaryAccount
            ->where(function ($q) use ($userId) {
                $q->where('primary_account_id', $userId)
                ->orWhere('linked_account_id', $userId);
            })
            ->firstOrFail();
    
        $wasActive = $link->status === 'active';
        $primary   = $link->primaryAccount; // NEW
        $link->delete();
    
        if ($wasActive) {
            $this->syncStandaloneSubscriptionAmount($primary); // NEW
        }
    
        return response()->json([
            'success' => true,
            'action'  => $wasActive ? 'unlinked' : 'cancelled',
        ]);
    }
 

    // ── Approval — called by estate admin or Echo Link admin dashboard ──
    public function approve(Request $request, int $id): JsonResponse
    {
        $link = AccountLink::with(['primaryAccount', 'linkedAccount'])->findOrFail($id); // CHANGED — added linkedAccount
    
        if ($link->status !== 'pending') {
            return response()->json(['error' => 'Link is not pending'], 422);
        }
    
        $approverType = $request->user()->role === 'admin' ? 'echo_link_admin' : 'estate_admin';
    
        $link->update([
            'status'            => 'active',
            'approved_by_type'  => $approverType,
            'approved_by_id'    => $request->user()->id,
            'approved_at'       => now(),
        ]);
    
        $primary = $link->primaryAccount;
        User::where('id', $link->linked_account_id)->update([
            'address_line_1' => $primary->address_line_1,
            'complex_name'   => $primary->complex_name,
            'suburb'         => $primary->suburb,
            'unit_number'    => $primary->unit_number,
            'latitude'       => $primary->latitude,
            'longitude'      => $primary->longitude,
        ]);
    
        $newMonthlyAmount = $this->syncStandaloneSubscriptionAmount($primary);
        $isEstateBilled   = $newMonthlyAmount === null && $primary->subscription()
            ->where('cancellation_reason', 'estate_optin')
            ->exists();
    
        // NEW — notify both sides
        if ($primary->email) {
            Mail::to($primary->email)->queue(new AccountLinkApprovedPrimaryMail(
                $primary,
                $link->linkedAccount,
                $isEstateBilled,
                $newMonthlyAmount,
            ));
        }
    
        if ($link->linkedAccount?->email) {
            Mail::to($link->linkedAccount->email)->queue(new AccountLinkedMail(
                $link->linkedAccount,
                $primary,
            ));
        }
    
        return response()->json(['success' => true]);
    }
    
    // ── reject() — eager-load both relations, send notice to primary only ──
    public function reject(Request $request, int $id): JsonResponse
    {
        $link = AccountLink::with(['primaryAccount', 'linkedAccount'])->findOrFail($id); // CHANGED
    
        if ($link->status !== 'pending') {
            return response()->json(['error' => 'Link is not pending'], 422);
        }
    
        $link->update(['status' => 'rejected']);
    
        if ($link->primaryAccount?->email) {
            Mail::to($link->primaryAccount->email)->queue(new AccountLinkRejectedMail(
                $link->primaryAccount,
                $link->linkedAccount,
            ));
        }
    
        return response()->json(['success' => true]);
    }

   public function eligibility(Request $request): JsonResponse
    {
        try {
            $userId = $request->user()->id;

            $isLinkedAsChild = AccountLink::where('linked_account_id', $userId)
                ->whereIn('status', ['pending', 'active'])
                ->exists();

            return response()->json(['is_primary' => !$isLinkedAsChild]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // Admin-forced unlink of an ACTIVE link. Distinct from destroy() above,
    // which only lets the primary account holder cancel/unlink their own.
    
    public function forceUnlink(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
    
        if (! in_array($user->role, ['admin', 'estate_billing'])) {
            abort(403);
        }
    
        $link = AccountLink::with('primaryAccount.employee.channels')->findOrFail($id);
    
        if ($link->status !== 'active') {
            return response()->json(['error' => 'Link is not active'], 422);
        }
    
        if ($user->role === 'estate_billing') {
            $channelIds = $user->accessibleChannelIds();
            $primaryChannelId = $link->primaryAccount?->employee?->channels->first()?->id;
    
            if (! $primaryChannelId || ! $channelIds->contains($primaryChannelId)) {
                abort(403, 'Not your estate.');
            }
        }
    
        $primary = $link->primaryAccount; // NEW — capture before delete
        $link->delete();
    
        $this->syncStandaloneSubscriptionAmount($primary); // NEW
    
        return response()->json(['success' => true, 'action' => 'force_unlinked']);
    }
}
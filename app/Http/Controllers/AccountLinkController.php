<?php

namespace App\Http\Controllers;

use App\Mail\AccountLinkApprovedPrimaryMail;
use App\Mail\AccountLinkedMail;
use App\Mail\AccountLinkRejectedMail;
use App\Models\AccountLink;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ChannelBillingService;
use App\Services\PayFastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AccountLinkController extends Controller
{

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


            $targetIsEstateOptedIn = Subscription::where('user_id', $targetId)
                ->where('cancellation_reason', 'estate_optin')
                ->whereNotNull('channel_subscription_id')
                ->exists();

            if ($targetIsEstateOptedIn) {
                $skipped[] = ['id' => $targetId, 'reason' => 'target_estate_opted_in'];
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
    // Cancel a pending request (primary-initiated), or unlink an active one
    public function destroy(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;

        $link = AccountLink::with(['primaryAccount', 'linkedAccount.subscription'])
            ->where('id', $id)
            ->where(function ($q) use ($userId) {
                $q->where('primary_account_id', $userId)
                ->orWhere('linked_account_id', $userId);
            })
            ->firstOrFail();

        $wasActive = $link->status === 'active';
        $primary   = $link->primaryAccount;
        $linkedSub = $link->linkedAccount?->subscription;


         if ($wasActive && ! $primary) {
            Log::error('destroy: AccountLink has no primaryAccount', ['link_id' => $link->id]);
            return response()->json(['error' => 'Link has no primary account'], 422);
        }


        if ($wasActive) {
            if ($linkedSub) {
                $linkedSub->update([
                    'channel_subscription_id' => null,
                    'cancellation_reason'     => null,
                    'status'                  => 'cancelled',
                    'ends_at'                 => now(),
                ]);
            }

            $link->linkedAccount?->update(['subscription_status' => 'cancelled']);

            try {
                Http::timeout(5)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
                        'Content-Type'  => 'application/json',
                    ])
                    ->post(rtrim(env('PTT_SERVER_URL'), '/') . '/payment-failed', [
                        'userId'       => $link->linked_account_id,
                        'forceSuspend' => true,
                        'reason'       => 'account_unlinked',
                    ]);
            } catch (\Throwable $e) {
                Log::warning('destroy: failed to notify Node of linked account suspension', [
                    'linked_user_id' => $link->linked_account_id,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        $link->delete();

        $syncFailed = false;

        if ($wasActive) {
            $sync = app(ChannelBillingService::class)->syncStandaloneSubscriptionAmount($primary);
            $syncFailed = $sync['failed'];
        }

        return response()->json([
            'success'             => true,
            'action'              => $wasActive ? 'unlinked' : 'cancelled',
            'billing_sync_failed' => $syncFailed,
        ]);

    }
 

    // ── Approval - called by estate admin or Echo Link admin dashboard ──
    public function approve(Request $request, int $id): JsonResponse
    {
        $link = AccountLink::with(['primaryAccount.employee.channels', 'linkedAccount'])->findOrFail($id);

        if ($link->status !== 'pending') {
            return response()->json(['error' => 'Link is not pending'], 422);
        }

        if (! in_array($request->user()->role, ['admin', 'estate_billing'])) {
            abort(403);
        }

        if (! $link->primaryAccount) {
            Log::error('approve: AccountLink has no primaryAccount', ['link_id' => $link->id]);
            return response()->json(['error' => 'Link has no primary account'], 422);
        }

        if ($request->user()->role === 'estate_billing') {
            $channelIds = $request->user()->accessibleChannelIds();
            $primaryChannelId = $link->primaryAccount->employee?->channels->first()?->id;

            if (! $primaryChannelId || ! $channelIds->contains($primaryChannelId)) {
                abort(403, 'Not your estate.');
            }
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

        // If the primary is on estate billing, fold the linked account into the
        // same channel subscription so it's picked up by activateOptedInHouseholds()
        // / suspendChannel() automatically, same as the primary is.
        $primarySubscription = $primary->subscription()
            ->where('cancellation_reason', 'estate_optin')
            ->whereNotNull('channel_subscription_id')
            ->first();

        $isEstateBilled = (bool) $primarySubscription;

        if ($isEstateBilled) {
            $link->linkedAccount?->subscription?->update([
                'channel_subscription_id' => $primarySubscription->channel_subscription_id,
                'cancellation_reason'     => 'estate_optin',
            ]);
            $newMonthlyAmount = null;
            $priceSyncFailed  = false;
        } else {
            $sync             = app(ChannelBillingService::class)->syncStandaloneSubscriptionAmount($primary);
            $newMonthlyAmount = $sync['amount'];
            $priceSyncFailed  = $sync['failed'];
        }


        // NEW — notify both sides
        if ($primary->email) {
            Mail::to($primary->email)->queue(new AccountLinkApprovedPrimaryMail(
                $primary,
                $link->linkedAccount,
                $isEstateBilled,
                $newMonthlyAmount,
                $priceSyncFailed,
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
            $user   = $request->user();
            $userId = $user->id;

            $isLinkedAsChild = AccountLink::where('linked_account_id', $userId)
                ->whereIn('status', ['pending', 'active'])
                ->exists();

            $estateSubscription = $user->subscription()
                ->where('cancellation_reason', 'estate_optin')
                ->whereNotNull('channel_subscription_id')
                ->first();

            $channel = $user->employee?->channels->first();

            $billingMode = $estateSubscription ? 'estate' : 'standalone';
            $amountPerLinkedAccount = $channel
                ? BillingService::unitPrice($channel->amount_per_linked_account)
                : null;

            return response()->json([
                'is_primary'                 => !$isLinkedAsChild,
                'billing_mode'               => $billingMode,
                'amount_per_linked_account'  => $amountPerLinkedAccount,
            ]);
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

        $link = AccountLink::with(['primaryAccount.employee.channels', 'linkedAccount.subscription'])->findOrFail($id);

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

        $primary   = $link->primaryAccount;
        $linkedSub = $link->linkedAccount?->subscription;


        if (! $primary) {
            Log::error('forceUnlink: AccountLink has no primaryAccount', ['link_id' => $link->id]);
            return response()->json(['error' => 'Link has no primary account'], 422);
        }


        // Unwind the linked account's coverage — no billing relationship covers
        // them anymore, whether they were folded into estate billing or riding
        // on the primary's standalone PayFast subscription.
        if ($linkedSub) {
            $linkedSub->update([
                'channel_subscription_id' => null,
                'cancellation_reason'     => null,
                'status'                  => 'cancelled',
                'ends_at'                 => now(),
            ]);
        }

        $link->linkedAccount?->update(['subscription_status' => 'cancelled']);

        try {
            Http::timeout(5)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
                    'Content-Type'  => 'application/json',
                ])
                ->post(rtrim(env('PTT_SERVER_URL'), '/') . '/payment-failed', [
                    'userId'       => $link->linked_account_id,
                    'forceSuspend' => true,
                    'reason'       => 'account_unlinked',
                ]);
        } catch (\Throwable $e) {
            Log::warning('forceUnlink: failed to notify Node of linked account suspension', [
                'linked_user_id' => $link->linked_account_id,
                'error'          => $e->getMessage(),
            ]);
        }

        $link->delete();


        $sync = app(ChannelBillingService::class)->syncStandaloneSubscriptionAmount($primary);

        return response()->json([
            'success'            => true,
            'action'             => 'force_unlinked',
            'billing_sync_failed' => $sync['failed'],
        ]);

    }
}
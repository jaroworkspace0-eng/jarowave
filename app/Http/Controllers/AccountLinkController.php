<?php

namespace App\Http\Controllers;

use App\Models\AccountLink;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
                'primaryAccount.channels:id,name,billing_model',
                'linkedAccount:id,name,phone,address_line_1,complex_name,suburb,unit_number',
            ])
            ->orderByDesc('created_at');

        if ($user->role === 'admin') {
            // sees every request, no filter
        } elseif ($user->role === 'estate_billing') {
            $channelIds = $user->accessibleChannelIds();

            $query->whereHas('primaryAccount.channels', function ($q) use ($channelIds) {
                $q->whereIn('channels.id', $channelIds);
            });
        } else {
            // household / primary account holder — only their own requests
            $query->where('primary_account_id', $user->id);
        }

        $links = $query->get()->map(function (AccountLink $l) use ($user) {
            $row = [
                'id'          => $l->id,
                'status'      => $l->status,
                'escalated'   => $l->escalated,
                'created_at'  => $l->created_at,
                'approved_at' => $l->approved_at,
                'linked_account' => $l->linkedAccount,
            ];

            if (in_array($user->role, ['admin', 'estate_billing'])) {
                $row['primary_account'] = $l->primaryAccount;
                $primaryChannel = $l->primaryAccount?->channels->first();
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
        $link = AccountLink::where('id', $id)
            ->where('primary_account_id', $request->user()->id)
            ->firstOrFail();

        if ($link->status === 'active') {
            // Unlinking an ACTIVE link — this is a "delink", not a cancel.
            // Does NOT revert the child's address; that stays as-is until
            // they update it themselves post-delink.
            $link->delete();
            return response()->json(['success' => true, 'action' => 'unlinked']);
        }

        $link->delete();
        return response()->json(['success' => true, 'action' => 'cancelled']);
    }

    // ── Approval — called by estate admin or Echo Link admin dashboard ──
    public function approve(Request $request, int $id): JsonResponse
    {
        $link = AccountLink::with('primaryAccount')->findOrFail($id);

        if ($link->status !== 'pending') {
            return response()->json(['error' => 'Link is not pending'], 422);
        }

        // $approverType = $request->input('approver_type', 'estate_admin'); // or 'echo_link_admin'
        $approverType = $request->user()->role === 'admin' ? 'echo_link_admin' : 'estate_admin';

        $link->update([
            'status'            => 'active',
            'approved_by_type'  => $approverType,
            'approved_by_id'    => $request->user()->id,
            'approved_at'       => now(),
        ]);

        // ── Address-sync — ONLY on approval ──
        $primary = $link->primaryAccount;
        User::where('id', $link->linked_account_id)->update([
            'address_line_1' => $primary->address_line_1,
            'complex_name'   => $primary->complex_name,
            'suburb'         => $primary->suburb,
            'unit_number'    => $primary->unit_number,
            'latitude'       => $primary->latitude,
            'longitude'      => $primary->longitude,
        ]);

        return response()->json(['success' => true]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $link = AccountLink::findOrFail($id);

        if ($link->status !== 'pending') {
            return response()->json(['error' => 'Link is not pending'], 422);
        }

        $link->update(['status' => 'rejected']);

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
}
<?php
// app/Http/Controllers/Api/HouseholdPairingController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockedHousehold;
use App\Models\HouseholdPairing;
use App\Models\HouseholdSetting;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HouseholdPairingController extends Controller
{
    public function __construct(protected NotificationService $notifications) {}

    // POST /api/household-pairings
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'receiver_id' => 'required|exists:users,id',
        ]);

        $requesterId = $request->user()->id;
        $receiverId  = $request->integer('receiver_id');

        if ($requesterId === $receiverId) {
            return response()->json(['message' => 'A household cannot pair with itself.'], 422);
        }

        // ── block check ──
        $isBlocked = BlockedHousehold::where(function ($q) use ($requesterId, $receiverId) {
            $q->where('user_id', $requesterId)
            ->where('blocked_user_id', $receiverId);
        })->orWhere(function ($q) use ($requesterId, $receiverId) {
            $q->where('user_id', $receiverId)
            ->where('blocked_user_id', $requesterId);
        })->exists();

        if ($isBlocked) {
            return response()->json(['message' => 'You cannot send a request to this household. Either you have blocked them, or they have blocked you.'], 422);
        }

        // ── find any existing pairing row regardless of status ──
        $existingPairing = HouseholdPairing::where(function ($q) use ($requesterId, $receiverId) {
            $q->where(function ($inner) use ($requesterId, $receiverId) {
                $inner->where('requester_id', $requesterId)
                    ->where('receiver_id', $receiverId);
            })->orWhere(function ($inner) use ($requesterId, $receiverId) {
                $inner->where('requester_id', $receiverId)
                    ->where('receiver_id', $requesterId);
            });
        })->first();

        // ── block if already pending or active ──
        if ($existingPairing && in_array($existingPairing->status, ['pending', 'active'])) {
            return response()->json(['message' => 'A pairing already exists between these households.'], 422);
        }

        $activeCount = HouseholdPairing::where(function ($q) use ($requesterId) {
            $q->where('requester_id', $requesterId)->orWhere('receiver_id', $requesterId);
        })->where('status', 'active')->count();

        if ($activeCount >= 10) {
            return response()->json(['message' => 'Maximum of 10 active pairings reached.'], 422);
        }

        // ── check receiver's autoAccept setting ──
        $receiverSettings = HouseholdSetting::where('user_id', $receiverId)->first();
        $autoAccept       = $receiverSettings?->auto_accept ?? false;

        // ── reuse existing dissolved/declined row, or create fresh ──
        if ($existingPairing) {
            $existingPairing->update([
                'requester_id' => $requesterId,
                'receiver_id'  => $receiverId,
                'status'       => $autoAccept ? 'active' : 'pending',
                'requested_at' => now(),
                'responded_at' => $autoAccept ? now() : null,
            ]);
            $pairing = $existingPairing;
        } else {
            $pairing = HouseholdPairing::create([
                'requester_id' => $requesterId,
                'receiver_id'  => $receiverId,
                'status'       => $autoAccept ? 'active' : 'pending',
                'requested_at' => now(),
                'responded_at' => $autoAccept ? now() : null,
            ]);
        }

        $receiver  = User::findOrFail($receiverId);
        $requester = $request->user();

        if ($autoAccept) {
            $this->notifications->send(
                recipient: $requester,
                type:      'pairing_accepted',
                title:     'Guardian Request Accepted',
                body:      "{$receiver->name} automatically accepted your guardian request.",
                data:      [
                    'pairing_id'    => $pairing->id,
                    'accepter_id'   => $receiverId,
                    'accepter_name' => $receiver->name,
                ],
            );

            $this->notifications->send(
                recipient: $receiver,
                type:      'pairing_accepted',
                title:     'New Guardian Added',
                body:      "{$requester->name} has been added as your mutual guardian.",
                data:      [
                    'pairing_id'    => $pairing->id,
                    'accepter_id'   => $requesterId,
                    'accepter_name' => $requester->name,
                ],
            );
        } else {
            $this->notifications->send(
                recipient: $receiver,
                type:      'pairing_request',
                title:     'New Guardian Request',
                body:      "{$requester->name} wants to pair with you as a mutual guardian.",
                data:      [
                    'pairing_id'     => $pairing->id,
                    'requester_id'   => $requesterId,
                    'requester_name' => $requester->name,
                ],
            );
        }

        // ── invalidate guardian cache on Node.js for both users ──
        if ($pairing->status === 'active') {
            foreach ([$requesterId, $receiverId] as $uid) {
                try {
                    Http::withHeaders([
                        'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
                        'Content-Type'  => 'application/json',
                    ])
                    ->timeout(3)
                    ->post(env('PTT_SERVER_URL') . '/invalidate-guardian-cache', [
                        'userId' => $uid,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("Guardian cache invalidation failed for userId={$uid}: {$e->getMessage()}");
                }
            }
        }


        return response()->json([
            'pairing'       => $pairing->load(['requester', 'receiver']),
            'auto_accepted' => $autoAccept,
        ], 201);
    }

    // PUT /api/household-pairings/{pairing}/accept
    public function accept(Request $request, HouseholdPairing $pairing): JsonResponse
    {
        $this->authorizeReceiver($request, $pairing);

        if ($pairing->status !== 'pending') {
            return response()->json(['message' => 'This request is no longer pending.'], 422);
        }

        $pairing->update([
            'status'       => 'active',
            'responded_at' => now(),
        ]);

        // ── Notify requester ──────────────────────────────────
        $requester = User::findOrFail($pairing->requester_id);
        $accepter  = $request->user();

        $this->notifications->send(
            recipient: $requester,
            type:      'pairing_accepted',
            title:     'Guardian Request Accepted',
            body:      "{$accepter->name} accepted your guardian pairing request.",
            data:      [
                'pairing_id'   => $pairing->id,
                'accepter_id'  => $accepter->id,
                'accepter_name' => $accepter->name,
            ],
        );

        return response()->json($pairing->load(['requester', 'receiver']));
    }

    // PUT /api/household-pairings/{pairing}/decline
    public function decline(Request $request, HouseholdPairing $pairing): JsonResponse
    {
        $this->authorizeReceiver($request, $pairing);

        if ($pairing->status !== 'pending') {
            return response()->json(['message' => 'This request is no longer pending.'], 422);
        }

        $pairing->update([
            'status'       => 'dissolved',
            'responded_at' => now(),
            'dissolved_at' => now(),
            'dissolved_by' => $request->user()->id,
        ]);

        // ── Notify requester ──────────────────────────────────
        $requester = User::findOrFail($pairing->requester_id);
        $decliner  = $request->user();

        $this->notifications->send(
            recipient: $requester,
            type:      'pairing_declined',
            title:     'Guardian Request Declined',
            body:      "{$decliner->name} declined your guardian pairing request.",
            data:      [
                'pairing_id'   => $pairing->id,
                'decliner_id'  => $decliner->id,
                'decliner_name' => $decliner->name,
            ],
        );

        // ── save block if requested ──
        if ($request->boolean('block')) {
            BlockedHousehold::firstOrCreate([
                'user_id'         => $request->user()->id,
                'blocked_user_id' => $pairing->requester_id,
            ]);
        }

        return response()->json(['message' => 'Pairing request declined.']);
    }

    // DELETE /api/household-pairings/{pairing}
    public function destroy(Request $request, HouseholdPairing $pairing): JsonResponse
    {
        $userId = $request->user()->id;

        if (!in_array($userId, [$pairing->requester_id, $pairing->receiver_id])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $pairing->update([
            'status'       => 'dissolved',
            'dissolved_at' => now(),
            'dissolved_by' => $userId,
        ]);

        // ── Notify the other party ────────────────────────────
        $otherId = $pairing->requester_id === $userId
            ? $pairing->receiver_id
            : $pairing->requester_id;

        $other = User::findOrFail($otherId);

        $this->notifications->send(
            recipient: $other,
            type:      'pairing_dissolved',
            title:     'Guardian Pairing Ended',
            body:      "{$request->user()->name} has dissolved your guardian pairing.",
            data:      [
                'pairing_id' => $pairing->id,
            ],
        );

        return response()->json(['message' => 'Pairing dissolved.']);
    }

    // GET /api/household-pairings
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $pairings = HouseholdPairing::with(['requester', 'receiver'])
            ->where('requester_id', $userId)
            ->orWhere('receiver_id', $userId)
            ->orderByDesc('requested_at')
            ->get()
            ->map(fn($p) => $this->formatPairing($p, $userId));

        return response()->json($pairings);
    }

    // GET /api/households/{household}/pairings
    public function forHousehold(User $household): JsonResponse
    {
        $pairings = HouseholdPairing::with(['requester', 'receiver'])
            ->where('requester_id', $household->id)
            ->orWhere('receiver_id', $household->id)
            ->get()
            ->map(fn($p) => $this->formatPairing($p, $household->id));

        return response()->json($pairings);
    }

    // GET /api/households/{household}/guardians
    public function guardians(User $household): JsonResponse
    {
        return response()->json($household->activeGuardians());
    }

    // ── Helpers ───────────────────────────────────────────────
    private function authorizeReceiver(Request $request, HouseholdPairing $pairing): void
    {
        if ($request->user()->id !== $pairing->receiver_id) {
            abort(403, 'Only the receiving household can respond to this request.');
        }
    }

    private function formatPairing(HouseholdPairing $pairing, int $userId): array
    {
        $other = $pairing->requester_id === $userId
            ? $pairing->receiver
            : $pairing->requester;

        return [
            'id'            => $pairing->id,
            'status'        => $pairing->status,
            'direction'     => $pairing->requester_id === $userId ? 'sent' : 'received',
            'requested_at'  => $pairing->requested_at,
            'responded_at'  => $pairing->responded_at,
            'dissolved_at'  => $pairing->dissolved_at,
            'household'     => $other,
        ];
    }

    // GET /api/users/search-community?q=John&suburb=Tembisa&complex=...
    public function searchCommunity(Request $request): JsonResponse
    {
        $request->validate([
            'q'       => 'required|string|min:2|max:100',
            'suburb'  => 'nullable|string',
            'complex' => 'nullable|string',
            'address' => 'nullable|string',
        ]);

        $q       = $request->input('q');
        $suburb  = $request->input('suburb');
        $complex = $request->input('complex');
        $address = $request->input('address');
        $selfId  = $request->user()->id;

        if (!$suburb && !$complex && !$address) {
            return response()->json([
                'message' => 'Your address is not set. Please update your profile before searching for guardians.',
            ], 422);
        }

        $query = User::where('id', '!=', $selfId)
            ->whereIn('role', ['household', 'resident'])
            ->where('is_active', true)
            // ── only show users who want to appear in search ──
            ->where(function ($q) {
                $q->whereHas('householdSetting', function ($s) {
                    $s->where('appear_in_search', true);
                })
                ->orWhereDoesntHave('householdSetting');
            })
            // ── filter out blocked users (both directions) ──
            ->whereDoesntHave('blockedByHouseholds', function ($b) use ($selfId) {
                $b->where('user_id', $selfId);         // people I have blocked
            })
            ->whereDoesntHave('blockedHouseholds', function ($b) use ($selfId) {
                $b->where('blocked_user_id', $selfId); // people who have blocked me
            })
            ->where(function ($nameQ) use ($q) {
                $nameQ->where('name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            });

        $query->where(function ($locQ) use ($suburb, $complex, $address) {
            if ($suburb) {
                $locQ->orWhere('suburb', 'like', "%{$suburb}%");
            }
            if ($complex) {
                $locQ->orWhere('complex_name', 'like', "%{$complex}%");
            }
            if ($address) {
                $streetPart = explode(' ', trim($address))[0] ?? $address;
                if (strlen($streetPart) > 3) {
                    $locQ->orWhere('address_line_1', 'like', "%{$streetPart}%");
                }
            }
        });

        $results = $query
            ->select('id', 'name', 'suburb', 'complex_name', 'address_line_1', 'unit_number')
            ->with(['householdSetting', 'blockedHouseholds', 'blockedByHouseholds'])
            ->limit(20)
            ->get()
            ->map(fn($u) => [
                'id'      => $u->id,
                'name'    => $u->name,
                'suburb'  => ($u->householdSetting?->show_suburb ?? true) ? $u->suburb : null,
                'complex' => $u->complex_name,
                'address' => $u->address_line_1,
                'unit'    => $u->unit_number,
            ]);

        return response()->json($results);
    }
}
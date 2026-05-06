<?php
// app/Http/Controllers/Api/HouseholdPairingController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HouseholdPairing;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        // In store() — replace the $exists check with this:
        $exists = HouseholdPairing::where(function ($q) use ($requesterId, $receiverId) {
            $q->where(function ($inner) use ($requesterId, $receiverId) {
                $inner->where('requester_id', $requesterId)
                    ->where('receiver_id', $receiverId);
            })->orWhere(function ($inner) use ($requesterId, $receiverId) {
                $inner->where('requester_id', $receiverId)
                    ->where('receiver_id', $requesterId);
            });
        })->whereIn('status', ['pending', 'active'])->exists();

        if ($exists) {
            return response()->json(['message' => 'A pairing already exists between these households.'], 422);
        }

        $activeCount = HouseholdPairing::where(function ($q) use ($requesterId) {
            $q->where('requester_id', $requesterId)->orWhere('receiver_id', $requesterId);
        })->where('status', 'active')->count();

        if ($activeCount >= 10) {
            return response()->json(['message' => 'Maximum of 10 active pairings reached.'], 422);
        }

        $pairing = HouseholdPairing::create([
            'requester_id' => $requesterId,
            'receiver_id'  => $receiverId,
            'status'       => 'pending',
            'requested_at' => now(),
        ]);

        // ── Notify receiver ───────────────────────────────────
        $receiver = User::findOrFail($receiverId);
        $requester = $request->user();

        $this->notifications->send(
            recipient: $receiver,
            type:      'pairing_request',
            title:     'New Guardian Request',
            body:      "{$requester->name} wants to pair with you as a mutual guardian.",
            data:      [
                'pairing_id'    => $pairing->id,
                'requester_id'  => $requesterId,
                'requester_name' => $requester->name,
            ],
        );

        return response()->json($pairing->load(['requester', 'receiver']), 201);
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
            title:     '✅ Guardian Request Accepted',
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
            title:     '❌ Guardian Request Declined',
            body:      "{$decliner->name} declined your guardian pairing request.",
            data:      [
                'pairing_id'   => $pairing->id,
                'decliner_id'  => $decliner->id,
                'decliner_name' => $decliner->name,
            ],
        );

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
            title:     '🔓 Guardian Pairing Ended',
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

        // At least one location field must be present to scope the search
        if (!$suburb && !$complex && !$address) {
            return response()->json([
                'message' => 'Your address is not set. Please update your profile before searching for guardians.',
            ], 422);
        }

        $query = User::where('id', '!=', $selfId)
            ->whereIn('role', ['household', 'resident'])
            ->where('is_active', true)
            ->where(function ($nameQ) use ($q) {
                $nameQ->where('name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            });

        // ── Community scope — match on suburb OR complex ──────
        // Suburb is the strongest signal (Tembisa vs Cape Town)
        // Complex narrows further within a suburb
        $query->where(function ($locQ) use ($suburb, $complex, $address) {
            if ($suburb) {
                $locQ->orWhere('suburb', 'like', "%{$suburb}%");
            }
            if ($complex) {
                $locQ->orWhere('complex_name', 'like', "%{$complex}%");
            }
            if ($address) {
                // Match on street name part only (first word or two)
                $streetPart = explode(' ', trim($address))[0] ?? $address;
                if (strlen($streetPart) > 3) {
                    $locQ->orWhere('address_line_1', 'like', "%{$streetPart}%");
                }
            }
        });

        $results = $query
            ->select('id', 'name', 'suburb', 'complex_name', 'address_line_1', 'unit_number')
            ->limit(20)
            ->get()
            ->map(fn($u) => [
                'id'      => $u->id,
                'name'    => $u->name,
                'suburb'  => $u->suburb,
                'complex' => $u->complex_name,
                'address' => $u->address_line_1,
                'unit'    => $u->unit_number,
            ]);

        return response()->json($results);
    }
}
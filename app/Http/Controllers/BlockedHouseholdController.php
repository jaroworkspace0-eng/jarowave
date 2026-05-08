<?php

namespace App\Http\Controllers;

use App\Models\BlockedHousehold;
use App\Models\HouseholdPairing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlockedHouseholdController extends Controller
{
    // POST /api/blocked-households
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'blocked_user_id' => 'required|exists:users,id',
        ]);

        $userId        = $request->user()->id;
        $blockedUserId = $request->integer('blocked_user_id');

        if ($userId === $blockedUserId) {
            return response()->json(['message' => 'You cannot block yourself.'], 422);
        }

        BlockedHousehold::firstOrCreate([
            'user_id'         => $userId,
            'blocked_user_id' => $blockedUserId,
        ]);

        // also dissolve any active pairing between them
        HouseholdPairing::where(function ($q) use ($userId, $blockedUserId) {
            $q->where('requester_id', $userId)->where('receiver_id', $blockedUserId);
        })->orWhere(function ($q) use ($userId, $blockedUserId) {
            $q->where('requester_id', $blockedUserId)->where('receiver_id', $userId);
        })->whereIn('status', ['active', 'pending'])->update([
            'status'       => 'dissolved',
            'responded_at' => now(),
        ]);

        return response()->json(['message' => 'Household blocked.']);
    }

    public function index(Request $request): JsonResponse
    {
        $blocked = BlockedHousehold::where('user_id', $request->user()->id)
            ->with('blockedUser:id,name,suburb')
            ->get()
            ->map(fn($b) => [
                'id'     => $b->blocked_user_id,
                'name'   => $b->blockedUser->name,
                'suburb' => $b->blockedUser->suburb,
            ]);

        return response()->json($blocked);
    }

    // DELETE /api/blocked-households/{blockedUserId}
    public function destroy(Request $request, int $blockedUserId): JsonResponse
    {
        BlockedHousehold::where('user_id', $request->user()->id)
            ->where('blocked_user_id', $blockedUserId)
            ->delete();

        return response()->json(['message' => 'Unblocked successfully.']);
    }
}

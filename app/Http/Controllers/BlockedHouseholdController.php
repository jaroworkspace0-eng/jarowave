<?php

namespace App\Http\Controllers;

use App\Models\BlockedHousehold;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlockedHouseholdController extends Controller
{
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

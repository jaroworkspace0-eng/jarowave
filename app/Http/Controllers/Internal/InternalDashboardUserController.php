<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class InternalDashboardUserController extends Controller
{
    public function me(Request $request)
    {
        if ($request->header('X-PTT-Secret') !== env('ASSIGN_SECRET')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $code = $request->bearerToken();
        $userId = $code ? Cache::get("dashboard-handshake:{$code}") : null;
        $user = $userId ? User::find($userId) : null;

        if (!$user) {
            return response()->json(['error' => 'Invalid handshake code'], 401);
        }

        return response()->json([
            'role' => $user->role,
            'channelIds' => $user->role === 'admin' ? [] : $user->accessibleChannelIds(),
        ]);
    }
}
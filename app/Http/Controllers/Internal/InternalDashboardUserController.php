<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class InternalDashboardUserController extends Controller
{
    public function me(Request $request)
    {
        if ($request->header('X-PTT-Secret') !== env('ASSIGN_SECRET')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Invalid session'], 401);
        }

        return response()->json([
            'role' => $user->role,
            'channelIds' => $user->role === 'admin' ? [] : $user->accessibleChannelIds(),
        ]);
    }
}
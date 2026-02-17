<?php

namespace App\Http\Controllers;

use App\Events\UserStatusUpdated;
use App\Models\ChannelEmployee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StatusController extends Controller
{
    public function updateStatus(Request $request)
    {
        // 1. Find the user first. If not found, it automatically returns a 404.
        $user = User::findOrFail($request->user_id);

        // 2. Update the user status directly on the model
        $user->update([
            'status' => $request->status
        ]);

        // 3. Get the employee relationship
        $employee = $user->employee; // No need for ->first() if it's a HasOne

        Log::info('Status: ' . $request->status);
        Log::info('UserId: ' . $request->user_id);
        Log::info('ChannelId: ' . $request->channel_id);
        Log::info('EmployeeId: ' . $employee->id);

        if ($employee) {
            // 4. Update the pivot table for the specific channel
            ChannelEmployee::where('employee_id', $employee->id)
                ->where('channel_id', $request->channel_id)
                ->update([
                    'is_online' => $request->status === 'online' ? 1 : 0,
                    'last_seen' => now(),
                ]);

            // 5. Broadcast the event for real-time UI updates
            broadcast(new UserStatusUpdated($request->user_id, $request->status))->toOthers();

            Log::info($user);

            return response()->json([
                'success' => true,
                'user_id' => $user->id,
                'status' => $request->status ,
                'channel_id' => $request->channel_id,
                'username' => $user->name
            ], 200);
        }

        return response()->json(['error' => 'Employee profile not found.'], 404);
    }
}

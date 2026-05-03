<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserNotificationController extends Controller
{
    // GET /api/notifications
    public function index(Request $request): JsonResponse
    {
        $notifications = UserNotification::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($notifications);
    }

    // GET /api/notifications/unread-count
    public function unreadCount(Request $request): JsonResponse
    {
        $count = UserNotification::where('user_id', $request->user()->id)
            ->unread()
            ->count();

        return response()->json(['count' => $count]);
    }

    // PUT /api/notifications/{id}/read
    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = UserNotification::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return response()->json($notification);
    }

    // PUT /api/notifications/read-all
    public function markAllRead(Request $request): JsonResponse
    {
        UserNotification::where('user_id', $request->user()->id)
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    // DELETE /api/notifications/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        UserNotification::where('user_id', $request->user()->id)
            ->findOrFail($id)
            ->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }
}

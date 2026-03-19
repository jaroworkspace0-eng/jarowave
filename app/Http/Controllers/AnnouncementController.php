<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnnouncementController extends Controller
{
    /**
     * List all announcements (paginated, newest first).
     */
    public function index(Request $request)
    {
        $announcements = Announcement::with('sender:id,name')
            ->latest()
            ->paginate(20);

        return response()->json($announcements);
    }

    /**
     * Send a new announcement.
     * Pushes to Node server which handles socket + FCM delivery.
     */
    public function send(Request $request)
    {
        $validated = $request->validate([
            'title'           => 'required|string|max:100',
            'message'         => 'required|string|max:1000',
            'type'            => 'required|in:general,urgent,update,policy',
            'target'          => 'required|in:all,client,users',
            'target_client_id'=> 'nullable|integer|exists:clients,id',
            'target_user_ids' => 'nullable|array',
            'target_user_ids.*'=> 'integer|exists:users,id',
        ]);

        $sender = auth()->user();

        // Resolve which user IDs to target
        $targetUserIds = null;
        if ($validated['target'] === 'client' && !empty($validated['target_client_id'])) {
            $targetUserIds = \App\Models\Employee::where('client_id', $validated['target_client_id'])
                ->pluck('user_id')
                ->toArray();
        } elseif ($validated['target'] === 'users' && !empty($validated['target_user_ids'])) {
            $targetUserIds = $validated['target_user_ids'];
        }

        // Persist to DB
        $announcement = Announcement::create([
            'title'           => $validated['title'],
            'message'         => $validated['message'],
            'type'            => $validated['type'],
            'target'          => $validated['target'],
            'target_client_id'=> $validated['target_client_id'] ?? null,
            'target_user_ids' => $targetUserIds ? json_encode($targetUserIds) : null,
            'sent_by'         => $sender->id,
            'sent_at'         => now(),
        ]);

        // Push to Node server
        try {
            $payload = [
                'title'   => $validated['title'],
                'message' => $validated['message'],
                'type'    => $validated['type'],
                'from'    => $sender->name,
            ];

            if ($targetUserIds) {
                $payload['targetUserIds'] = $targetUserIds;
            }

            $response = Http::timeout(10)
                ->withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                ->post(env('PTT_SERVER_URL') . '/send-announcement', $payload);

            $delivered = $response->json('socketDelivered', false);
            $fcmSent   = $response->json('fcmSent', 0);

            Log::info("Announcement sent: socketDelivered={$delivered} fcmSent={$fcmSent}");

        } catch (\Exception $e) {
            Log::warning('PTT announcement push failed: ' . $e->getMessage());
        }

        return response()->json([
            'success'      => true,
            'announcement' => $announcement->load('sender:id,name'),
        ]);
    }

    /**
     * Delete an announcement.
     */
    public function destroy(Announcement $announcement)
    {
        $announcement->delete();
        return response()->json(['success' => true]);
    }
}

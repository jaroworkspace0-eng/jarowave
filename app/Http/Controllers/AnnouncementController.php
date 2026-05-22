<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
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
            'title'                => 'required|string|max:100',
            'message'              => 'required|string|max:1000',
            'type'                 => 'required|in:general,urgent,update,policy,payment,update_app',
            'target'               => 'required|in:all,client,users,household',
            'target_client_ids'    => 'nullable|array',
            'target_client_ids.*'  => 'integer|exists:clients,id',
            'target_user_ids'      => 'nullable|array',
            'target_user_ids.*'    => 'integer|exists:users,id',
            'target_household_ids' => 'nullable|array',
            'target_household_ids.*'=> 'integer|exists:households,id',
            'payment_subtype'      => 'nullable|string',
            'app_version'          => 'nullable|string',
            'playstore_url'        => 'nullable|url',
            'min_version_code'     => 'nullable|integer|min:1',
            'force_update'         => 'nullable|boolean',
        ]);

        // Extra validation: update_app requires app_version + playstore_url
        if ($validated['type'] === 'update_app') {
            if (empty($validated['app_version']) || empty($validated['playstore_url'])) {
                return response()->json([
                    'message' => 'app_version and playstore_url are required for update_app announcements.',
                ], 422);
            }
            if (!empty($validated['force_update']) && empty($validated['min_version_code'])) {
                return response()->json([
                    'message' => 'min_version_code is required when force_update is enabled.',
                ], 422);
            }
        }

        $sender = auth()->user();

        // Resolve target user IDs for socket delivery
        $targetUserIds = null;
        if ($validated['target'] === 'client' && !empty($validated['target_client_ids'])) {
            $targetUserIds = \App\Models\Employee::whereIn('client_id', $validated['target_client_ids'])
                ->pluck('user_id')
                ->toArray();
        } elseif ($validated['target'] === 'users' && !empty($validated['target_user_ids'])) {
            $targetUserIds = $validated['target_user_ids'];
        } elseif ($validated['target'] === 'household' && !empty($validated['target_household_ids'])) {
            $targetUserIds = User::whereIn('id', $validated['target_household_ids'])
                ->pluck('user_id')
                ->toArray();
        }

        $announcement = Announcement::create([
            'title'                => $validated['title'],
            'message'              => $validated['message'],
            'type'                 => $validated['type'],
            'target'               => $validated['target'],
            'target_client_ids'    => $validated['target_client_ids'] ?? null,
            'target_user_ids'      => $targetUserIds ? $targetUserIds : null,
            'target_household_ids' => $validated['target_household_ids'] ?? null,
            'payment_subtype'      => $validated['payment_subtype'] ?? null,
            'app_version'          => $validated['app_version'] ?? null,
            'playstore_url'        => $validated['playstore_url'] ?? null,
            'min_version_code'     => $validated['min_version_code'] ?? null,
            'force_update'         => $validated['force_update'] ?? false,
            'sent_by'              => $sender->id,
            'sent_at'              => now(),
        ]);

        // Push to Node
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

    public function appConfig(): JsonResponse
    {
        $announcement = Announcement::where('type', 'update_app')
            ->where('force_update', true)
            ->whereNotNull('min_version_code')
            ->latest('sent_at')
            ->first();

        if (!$announcement) {
            return response()->json(['force_update' => false]);
        }

        return response()->json([
            'force_update'     => true,
            'min_version_code' => $announcement->min_version_code,
            'app_version'      => $announcement->app_version,
            'message'          => $announcement->message,
            'playstore_url'    => $announcement->playstore_url,
        ]);
    }
}

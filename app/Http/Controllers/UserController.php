<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        return Inertia::render("Users/Index",[
            "users" => User::all()
        ]);

    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function toggleStatus(User $user)
    {
        $user->update([
            'is_active' => !$user->is_active
        ]);


        // When marking user inactive
         if (!$user->is_active) {
            try {
                Http::timeout(5)
                    ->withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                    ->post(env('PTT_SERVER_URL') . '/force-disconnect', [
                        'userId' => $user->id,
                        'reason' => 'user_inactive',
                    ]);
            } catch (\Exception $e) {
                Log::warning('PTT force-disconnect failed: ' . $e->getMessage());
            }
        }

          return response()->json([
                'success' => true,
                'message' => 'User status updated successfully.',
            ]);

    }

    public function users()
    {
        abort_if(app()->environment('production'), 403);
        
        return response()->json(
            User::whereIn('role', ['household', 'resident'])
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
        );
    }

    public function updateFcmToken(Request $request): JsonResponse
    {
        $request->validate([
            'fcm_token' => 'required|string',
            'device_id' => 'nullable|string',
        ]);

        $request->user()->update([
            'fcm_token'            => $request->input('fcm_token'),
            'fcm_device_id'        => $request->input('device_id'),
            'fcm_token_updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }


    public function getFcmToken(Request $request, int $userId): JsonResponse
    {

        // Internal only — verify PTT secret
        if ($request->header('X-PTT-Secret') !== env('ASSIGN_SECRET')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user = User::find($userId);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json([
            'fcm_token' => $user->fcm_token,
            'device_id' => $user->fcm_device_id,
        ]);
    }
}

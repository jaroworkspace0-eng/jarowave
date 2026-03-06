<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\EmergencyAlert;
use Illuminate\Http\Request;

class EmergencyAlertController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, string $id)
{
    // 1. Fetch with relationships
    // Note: Ensure your EmergencyAlert model has these relationships defined!
    $alert = EmergencyAlert::with(['user', 'channel', 'client', 'resolver'])
        ->findOrFail($id);

    // 2. Optional: Security check
    // Ensure the logged-in user belongs to the same client as the alert
    if ($alert->client_id !== $request->user()->client_id) {
        return response()->json(['status' => 'error', 'message' => 'Unauthorized access to alert.'], 403);
    }

    return response()->json([
        'status' => 'success',
        'data' => [
            'id' => $alert->id,
            'sender' => $alert->user->name,
            'channel' => $alert->channel->name,
            'location' => [
                'lat' => (float)$alert->latitude,
                'lng' => (float)$alert->longitude,
            ],
            'is_resolved' => (bool)$alert->is_resolved,
            'timestamp' => $alert->created_at->toIso8601String(),
            'formatted_time' => $alert->created_at->format('H:i:s'),
        ]
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
        $request->validate([
            'channel_id' => 'required|exists:channels,id',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'accuracy' => 'nullable|string',
        ]);


        $channel = Channel::find($request->channel_id);

        $alert = EmergencyAlert::create([
            'user_id' => auth()->id(),
            'channel_id' => $request->channel_id,
            'client_id' => $channel->client_id,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'accuracy' => $request->accuracy,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Emergency alert created successfully.',
            'data' => [
                'id' => $alert->id,
                'timestamp' => $alert->created_at->toIso8601String(),
                'formatted_time' => $alert->created_at->format('H:i:s'),
            ]
        ], 201);
    }

    /**
     * Display the specified resource.
     */
   public function show(Request $request, string $id)
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
// Change the signature from (Request $request, EmergencyAlert $alert) 
// TO (Request $request, $id)
public function update(Request $request, $id)
{
    try {
        // 1. Manually find the record
        $alert = EmergencyAlert::find($id);

        if (!$alert) {
            return response()->json(['status' => 'error', 'message' => 'Alert ID not found in DB'], 404);
        }

        // 2. Update the values directly
        $alert->latitude = $request->latitude;
        $alert->longitude = $request->longitude;
        $alert->accuracy = $request->accuracy;
        
        $alert->save();

        return response()->json([
            'status' => 'success',
            'message' => 'GPS Synced'
        ]);

    } catch (\Exception $e) {
        // This will now definitely show up in your React Native logs
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}

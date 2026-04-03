<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\EmergencyAlert;
use App\Models\EmergencyResolution;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmergencyAlertController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, string $id)
{
    // 1. Fetch with relationships
    // Note: Ensure your EmergencyAlert model has these relationships defined!
    $alert = EmergencyAlert::with(['user', 'channels', 'client', 'resolver'])
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
            'channel' => $alert->channels->name,
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

        // Check if the user already has an UNRESOLVED alert from the last 2 minutes
        $existingAlert = EmergencyAlert::where('user_id', auth()->id())
            ->where('is_resolved', false)
            ->where('created_at', '>', now()->subMinutes(2))
            ->first();

        if ($existingAlert) {
            // Instead of a new record, just return the existing one
            // This prevents "Alert Storms" in your database
            return response()->json([
                'status' => 'success',
                'message' => 'Alert already active. Updating location.',
                'data' => ['id' => $existingAlert->id]
            ], 200); 
        }

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

    public function alertAccept(Request $request)
    {
        $request->validate([
            'emergency_alert_id' => 'required|exists:emergency_alerts,id',
            'responder_user_id'  => 'required|exists:users,id',
            'start_latitude'     => 'nullable|numeric',
            'start_longitude'    => 'nullable|numeric',
        ]);

        try {
            // 1. USE A TRANSACTION: This ensures that if two people click at the exact 
            // same millisecond, the database handles them one by one.
            return DB::transaction(function () use ($request) {
                
                // 2. CHECK FOR EXISTING RESOLUTION: 
                // We look for any resolution already tied to this alert.
                $existing = EmergencyResolution::where('emergency_alert_id', $request->emergency_alert_id)
                    ->first();

                if ($existing && $existing->responder_user_id !== null) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This alert has already been claimed by another Responder.',
                    ], 409); // 409 = Conflict
                }

                // 3. CREATE OR UPDATE:
                // Using updateOrCreate ensures we don't double-up records.
                $resolution = EmergencyResolution::updateOrCreate(
                    ['emergency_alert_id' => $request->emergency_alert_id],
                    [
                        'responder_user_id' => $request->responder_user_id,
                        'status'            => 'responding', // Force initial status
                        'accepted_at'       => now(),
                        // We store where the patroller WAS when they clicked accept
                        'start_latitude'    => $request->start_latitude,
                        'start_longitude'   => $request->start_longitude,
                        'confirmation_status' => 'pending',
                    ]
                );

                return response()->json([
                    'status'  => 'success',
                    'message' => 'Emergency alert accepted.',
                    'data'    => $resolution
                ]);
            });

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'System error: ' . $e->getMessage()
            ], 500);
        }
    }


    public function emergencyResolutionUpdate(Request $request)
    {
        $request->validate([
            'emergency_alert_id' => 'required|exists:emergency_alerts,id',
            'responder_user_id'  => 'required|exists:users,id',
            'responder_name'     => 'nullable|string',
            'status'             => 'required|string',
            'arrival_latitude'   => 'nullable|numeric',
            'arrival_longitude'  => 'nullable|numeric',
            'notes'              => 'nullable|string',
            'resolution_time'    => 'nullable|date',
            'response_duration'  => 'nullable|numeric',
            'distance_traveled'  => 'nullable|numeric',
            'channel_id'         => 'nullable|exists:channels,id',
        ]);

        try {
            return DB::transaction(function () use ($request) {
                // 1. Find the resolution record using the Alert ID
                $resolution = EmergencyResolution::where('emergency_alert_id', $request->emergency_alert_id)
                    ->firstOrFail();

                // 2. Automated Logic for 'on_site' status
                if ($request->status === 'on_site') {
                    $resolution->arrival_time = now();
                    
                    // Calculate response time automatically if we have the accept time
                    if ($resolution->accepted_at) {
                        $resolution->response_duration = $resolution->accepted_at->diffInSeconds(now());
                    }
                }

                // 3. Update the Resolution Record
                $resolution->update([
                'status'               => $request->status,
                'notes'                => $request->notes,
                'arrival_latitude'     => $request->arrival_latitude,
                'arrival_longitude'    => $request->arrival_longitude,
                'resolution_time'      => ($request->status === 'resolved') ? now() : $resolution->resolution_time,
                // When responder marks resolved, set confirmation to pending — awaiting victim
                'confirmation_status'  => ($request->status === 'resolved') ? 'pending' : null,
                'responder_name'       => $request->responder_name ?? null,
                'response_duration'    => $request->response_duration ?? $resolution->response_duration,
                'distance_traveled'    => $request->distance_traveled ?? $resolution->distance_traveled,
            ]);

                // 4. Update the Parent Alert if the situation is finished
                if (in_array($request->status, ['resolved', 'false_alarm'])) {
                    $alert = EmergencyAlert::findOrFail($request->emergency_alert_id);
                    $alert->update([
                        'is_resolved' => true,
                        'resolved_at' => now(),
                        'resolved_by' => $request->responder_user_id,
                    ]);
                }

                return response()->json([
                    'status'  => 'success',
                    'message' => "Status updated to: {$request->status}",
                    'data'    => $resolution
                ]);
            });

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Update failed: ' . $e->getMessage()
            ], 500);
        }
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
            $alert = EmergencyAlert::find($id);

            if (!$alert) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Alert ID not found in DB'
                ], 404);
            }

            $alert->latitude  = $request->latitude;
            $alert->longitude = $request->longitude;
            $alert->accuracy  = $request->accuracy;
            $alert->save();

            return response()->json([
                'status'  => 'success',
                'message' => 'GPS Synced'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }


    public function list(Request $request)
    {
        $user = auth()->user();
        $isAdmin = $user->role === 'admin';

        // admin → no scope
        // client role → scope by their own client record
        // employee/other → scope by their employee's client
        if ($isAdmin) {
            $clientId = null;
        } elseif ($user->role === 'client') {
            $clientId = \App\Models\Client::where('user_id', $user->id)->value('id');
        } else {
            $clientId = $user->employee?->client_id;
        }

        $alerts = EmergencyAlert::with([
            'user', 
            'channel', 
            'client.user', 
            'resolver', 
            'resolution.responder'
            ])
            ->when(!$isAdmin, fn($q) => $q->where('client_id', $clientId))
            ->latest()
            ->paginate(20);

        return response()->json($alerts);
    }

    public function resolve(Request $request, $id)
    {
        $alert = EmergencyAlert::findOrFail($id);
        $alert->update([
            'is_resolved' => true,
            'resolved_at' => now(),
            'resolved_by' => auth()->id(),
        ]);
        return response()->json(['message' => 'Alert resolved successfully']);
    }

    public function destroy(string $id)
    {
        EmergencyAlert::findOrFail($id)->delete();
        return response()->json(['message' => 'Alert deleted']);
    }

    public function confirm(Request $request, $alertId)
    {
        $request->validate([
            'confirmed_at'   => 'required|string',
            'confirmed_by'   => 'required|string|in:victim,timeout,forced',
            'victim_response'=> 'nullable|string|max:500',
        ]);

        $resolution = EmergencyResolution::where('emergency_alert_id', $alertId)
                ->orWhere('id', $alertId)
                ->latest()
                ->firstOrFail();

        $resolution->update([
            'confirmation_status' => $request->confirmed_by === 'victim' ? 'confirmed' : 
                                    ($request->confirmed_by === 'forced' ? 'confirmed' : 'auto_confirmed'),
            'confirmed_at'        => $request->confirmed_at,
            'confirmed_by'        => $request->confirmed_by,
            'victim_response'     => $request->victim_response ?? null,
        ]);

        // Also mark the parent emergency alert as fully resolved
        EmergencyAlert::where('id', $alertId)->update([
        'is_resolved' => true,
        'resolved_at' => now(),
    ]);

        return response()->json([
            'success' => true,
            'message' => 'Resolution confirmed',
            'data'    => $resolution,
        ]);
    }

}

<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChannelResource;
use App\Models\Channel;
use App\Models\Client;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ChannelController extends Controller
{
    /**
     * Display a listing of the resource.
     */
   
    public function index()
    {
        $channels = Channel::with('client') 
                    ->orderBy('created_at', 'desc')
                    ->paginate(10); 

        return response()->json([
            'channels' => $channels ,
        ]);
    }

// fetch channels for the app
    public function getChannels(Request $request)
    {
        // 1. Get user with relationship (mimicking login)
        $user = $request->user()->load('employee.channel');

        // 2. Map the data exactly the same way
        $channels = $user->employee?->channel->map(function($channel) {
            return [
                'id' => $channel->id,
                'name' => $channel->name,
            ];
        }) ?? collect([]);

        // 3. Return the array directly
        return response()->json($channels);
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
        $validated = $request->validate([
            'name' => 'required|string',
            'category' => 'required|string',
            'type' => 'required|string',
            'client_id' => 'required',
        ]);

        $channel = Channel::create($validated);

         return response()->json([
            'success' => true,
            'message' => 'Channel created successfully!',
            'channel' => $channel,
        ]);

    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
           return Channel::where('is_active', 1)->get();
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Channel $channel)
    {
        // If you are using a separate edit page
        return Inertia::render('Channels/Edit', [
            'channel' => $channel->load('client'),
            'clients' => Client::all()
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Channel $channel)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'client_id' => 'required|exists:clients,id',
            'category' => 'required',
            'type' => 'required',
        ]);

        $channel->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Channel updated successful.',
            'channel' => $channel,
        ]);

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Channel $channel)
    {
        $channel->delete();

         return response()->json([
            'success' => true,
            'message' => 'Channel deleted successfully.',
            'channel' => $channel,
        ]);

    }

    public function toggleStatus(Channel $channel)
    {
        // Toggle 1 to 0 or 0 to 1
        $channel->update([
            'is_active' => !$channel->is_active
        ]);

         return response()->json([
            'success' => true,
            'message' => 'Channels status updated successfully.',
            'channel' => $channel,
        ]);

        
    }

public function getUnits(Channel $channel)
{
    $units = $channel->employees()->with('user')->get();

    $formattedUnits = $units->mapWithKeys(function ($unit) {
        if (!$unit->user) return [];

        // 🔑 Use the ID as the key to prevent overwriting
        return [
            $unit->user_id => [ 
                'userId' => $unit->user->id,
                'name'     => $unit->user->name,
                'isOnline'   => $unit->user->status ?? 'offline',
                'lastSeen' => $unit->pivot->last_seen ?? null,
                'role'     => $unit->user->occupation ?? 'Guard',
            ]
        ];
    });

    return response()->json($formattedUnits);
}
}

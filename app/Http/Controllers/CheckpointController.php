<?php

namespace App\Http\Controllers;

use App\Models\Checkpoint;
use App\Models\Client;
use Illuminate\Http\Request;

class CheckpointController extends Controller
{
    // GET /api/clients/{clientId}/checkpoints
    public function index($clientId)
    {
        $client = Client::with('user')->findOrFail($clientId);

        $checkpoints = Checkpoint::where('client_id', $clientId)
            ->withCount('scans')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'client'      => $client,
            'checkpoints' => $checkpoints,
        ]);
    }

    // POST /api/clients/{clientId}/checkpoints
    public function store(Request $request, $clientId)
    {
        $request->validate([
            'name' => 'required|string|max:100',
        ]);

        Client::findOrFail($clientId); // ensure client exists

        $checkpoint = Checkpoint::create([
            'client_id' => $clientId,
            'name'      => $request->name,
        ]);

        return response()->json([
            'message'    => 'Checkpoint created successfully.',
            'checkpoint' => $checkpoint,
        ], 201);
    }

    // DELETE /api/checkpoints/{id}
    public function destroy($id)
    {
        $checkpoint = Checkpoint::findOrFail($id);
        $checkpoint->delete();

        return response()->json(['message' => 'Checkpoint deleted.']);
    }

    // GET /api/checkpoints/{id}/scans
    public function scans(Request $request, $id)
    {
        $checkpoint = Checkpoint::with('client.user')->findOrFail($id);

        $scans = $checkpoint->scans()
            ->with('guard:id,name,phone')
            ->orderByDesc('scanned_at')
            ->paginate(20);

        return response()->json([
            'checkpoint' => $checkpoint,
            'scans'      => $scans,
        ]);
    }
}
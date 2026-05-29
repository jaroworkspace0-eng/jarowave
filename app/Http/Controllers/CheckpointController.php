<?php

namespace App\Http\Controllers;

use App\Models\Checkpoint;
use App\Models\CheckpointScan;
use App\Models\Client;
use Illuminate\Http\Request;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class CheckpointController extends Controller
{
    // ── List all checkpoints for a client ─────────────────────────────────────
    public function index(Request $request, $clientId)
    {
        $client = Client::findOrFail($clientId);

        $checkpoints = Checkpoint::where('client_id', $clientId)
            ->withCount('scans')
            ->with('latestScan.securityGuard')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'client'      => $client->load('user'),
            'checkpoints' => $checkpoints,
        ]);
    }

    // ── Create a new checkpoint ───────────────────────────────────────────────
    public function store(Request $request, $clientId)
    {
        $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
        ]);

        $client = Client::findOrFail($clientId);

        $checkpoint = Checkpoint::create([
            'client_id'   => $client->id,
            'name'        => $request->name,
            'description' => $request->description,
        ]);

        return response()->json([
            'message'    => 'Checkpoint created.',
            'checkpoint' => $checkpoint,
        ], 201);
    }

    // ── Update a checkpoint ───────────────────────────────────────────────────
    public function update(Request $request, $clientId, $checkpointId)
    {
        $request->validate([
            'name'        => 'sometimes|required|string|max:100',
            'description' => 'nullable|string|max:255',
            'is_active'   => 'sometimes|boolean',
        ]);

        $checkpoint = Checkpoint::where('client_id', $clientId)
            ->findOrFail($checkpointId);

        $checkpoint->update($request->only('name', 'description', 'is_active'));

        return response()->json([
            'message'    => 'Checkpoint updated.',
            'checkpoint' => $checkpoint,
        ]);
    }

    // ── Delete a checkpoint ───────────────────────────────────────────────────
    public function destroy($clientId, $checkpointId)
    {
        $checkpoint = Checkpoint::where('client_id', $clientId)
            ->findOrFail($checkpointId);

        $checkpoint->delete();

        return response()->json(['message' => 'Checkpoint deleted.']);
    }

    // ── Get scan logs for a checkpoint ────────────────────────────────────────
    public function scans(Request $request, $clientId, $checkpointId)
    {
        $checkpoint = Checkpoint::where('client_id', $clientId)
            ->findOrFail($checkpointId);

        $scans = CheckpointScan::where('checkpoint_id', $checkpoint->id)
            ->with('securityGuard')
            ->orderBy('scanned_at', 'desc')
            ->paginate(20);

        return response()->json([
            'checkpoint' => $checkpoint,
            'scans'      => $scans,
        ]);
    }

    // ── Get all scan logs for a client (across all checkpoints) ──────────────
    public function allScans(Request $request, $clientId)
    {
        $client = Client::findOrFail($clientId);

        $checkpointIds = Checkpoint::where('client_id', $clientId)->pluck('id');

        $scans = CheckpointScan::whereIn('checkpoint_id', $checkpointIds)
            ->with(['checkpoint', 'securityGuard'])
            ->orderBy('scanned_at', 'desc')
            ->paginate(20);

        return response()->json([
            'client' => $client->load('user'),
            'scans'  => $scans,
        ]);
    }

    // ── QR code image for a checkpoint (PNG) ─────────────────────────────────
    public function qrImage($clientId, $checkpointId)
    {
        $checkpoint = Checkpoint::where('client_id', $clientId)
            ->findOrFail($checkpointId);

        $qr = QrCode::format('png')
            ->size(300)
            ->errorCorrection('H')
            ->generate($checkpoint->token);

        return response($qr, 200)->header('Content-Type', 'image/png');
    }
}
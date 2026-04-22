<?php

namespace App\Http\Controllers;

use App\Models\DvRecording;
use App\Models\EmergencyAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DvRecordingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // 1. Validate the incoming request
        $channelId = $request->query('channel_id');
        $limit = $request->query('limit', 10); // Default to 10 if not provided

        // 2. Build the query
        $recordings = DvRecording::with(['user:id,name']) // Load the victim/user info
            ->when($channelId, function ($query, $channelId) {
                return $query->where('channel_id', $channelId);
            })
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get();

        // 3. Return the collection
        return response()->json([
            'data' => $recordings->map(function ($rec) {
                return [
                    'id'            => $rec->id,
                    'victim_name'   => $rec->user->name ?? 'Unknown',
                    'started_at'    => $rec->started_at,
                    'duration_secs' => $rec->duration_secs,
                    'stream_url'    => $rec->stream_url,
                    'is_finalised'  => $rec->is_finalised,
                    'alert_id' => $rec->alert_id,
                    'file_name' => $rec->file_path ? basename($rec->file_path) : null,
                ];
            })
        ]);
    }

    // ── GET /api/dv-recordings/{alertId} ─────────────────────
    // Returns metadata for a DV recording linked to an alert.
    // Used by CPF dashboard and admin panel.
    public function show(int $alertId): JsonResponse
    {
        $recording = DvRecording::with(['user:id,name,phone', 'alert.channel:id,name'])
            ->where('alert_id', $alertId)
            ->orderByDesc('started_at')
            ->first();
 
        if (!$recording) {
            return response()->json(['message' => 'No recording found for this alert'], 404);
        }
 
        return response()->json([
            'id'            => $recording->id,
            'alert_id'      => $recording->alert_id,
            'channel_id'    => $recording->channel_id,
            'channel_name'  => $recording->alert->channel->name ?? null,
            'victim_name'   => $recording->user->name ?? null,
            'victim_phone'  => $recording->user->phone ?? null,
            'started_at'    => $recording->started_at,
            'ended_at'      => $recording->ended_at,
            'chunk_count'   => $recording->chunk_count,
            'duration_secs' => $recording->duration_secs,
            'is_finalised'  => $recording->is_finalised,
            'stream_url'    => $recording->stream_url,  // null if still recording
        ]);
    }
 
    // ── GET /api/dv-recordings/{alertId}/stream ───────────────
    // Streams the WAV file. Supports HTTP Range so browser
    // <audio> elements can seek. Auth guard on this route
    // ensures only CPF/admin roles can access recordings.
    public function stream(int $alertId): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $recording = DvRecording::where('alert_id', $alertId)->first();
 
        if (!$recording) {
            return response()->json(['message' => 'Recording not found'], 404);
        }
 
        if (!$recording->is_finalised || !$recording->file_path) {
            return response()->json([
                'message' => 'Recording still in progress — try again after the alert resolves'
            ], 425);
        }
 
        $filePath = $recording->file_path;
 
        if (!file_exists($filePath)) {
            return response()->json(['message' => 'Audio file missing from disk'], 404);
        }
 
        $fileSize = filesize($filePath);
        $rangeHeader = request()->header('Range');

        dd([
    'path' => $filePath,
    'exists' => file_exists($filePath),
    'size' => file_exists($filePath) ? filesize($filePath) : 'N/A',
]);
 
        if ($rangeHeader) {
            // Parse range:  bytes=start-end
            preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches);
            $start = (int) $matches[1];
            $end   = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : $fileSize - 1;
            $length = $end - $start + 1;
 
            return response()->stream(function () use ($filePath, $start, $length) {
                $fp = fopen($filePath, 'rb');
                fseek($fp, $start);
                $remaining = $length;
                while ($remaining > 0 && !feof($fp)) {
                    $chunk = fread($fp, min(8192, $remaining));
                    echo $chunk;
                    $remaining -= strlen($chunk);
                    flush();
                }
                fclose($fp);
            }, 206, [
                'Content-Type'   => 'audio/wav',
                'Content-Range'  => "bytes {$start}-{$end}/{$fileSize}",
                'Content-Length' => $length,
                'Accept-Ranges'  => 'bytes',
            ]);
        }
 
        // Full file
        return response()->stream(function () use ($filePath) {
            readfile($filePath);
        }, 200, [
            'Content-Type'        => 'audio/wav',
            'Content-Length'      => $fileSize,
            'Accept-Ranges'       => 'bytes',
            'Content-Disposition' => "attachment; filename=\"dv_alert_{$alertId}.wav\"",
        ]);
    }
 
    // ── Called by Node.js server (internal) ───────────────────
    // Node.js calls this via HTTP after finalising the WAV file,
    // so Laravel keeps the DB up to date.
    // POST /api/internal/dv-recordings/{alertId}/finalise
    // Protected by a shared internal secret header, not user auth.
   public function finalise(Request $request, int $alertId): JsonResponse
    {

        $secret = $request->header('X-PTT-Secret');
        if ($secret !== env('ASSIGN_SECRET')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
 
        $validated = $request->validate([
            'file_path'     => 'required|string|max:500',
            'chunk_count'   => 'required|integer|min:0',
            'duration_secs' => 'required|numeric|min:0',
            'channel_id'    => 'nullable|integer',
            'user_id'       => 'nullable|integer',
            'started_at'    => 'nullable|date',
        ]);
 
        // ── Log the call so you can confirm it's reaching Laravel ──
        Log::info('[DV] finalise called', ['alert_id' => $alertId, 'data' => $validated]);
 
        // Verify the emergency alert actually exists first
        $alertExists = \App\Models\EmergencyAlert::find($alertId);
        if (!$alertExists) {
            Log::warning('[DV] finalise — emergency alert not found', ['alert_id' => $alertId]);
            return response()->json(['message' => 'Emergency alert not found', 'alert_id' => $alertId], 404);
        }
 
        $recording = DvRecording::updateOrCreate(
            [
                'alert_id' => $alertId,   // match on alert_id
            ],
            [
                'channel_id'    => $validated['channel_id']    ?? $alertExists->channel_id,
                'user_id'       => $validated['user_id']        ?? $alertExists->user_id,
                'started_at'    => $validated['started_at']    ?? now(),
                'ended_at'      => now(),
                'file_path'     => $validated['file_path'],
                'chunk_count'   => $validated['chunk_count'],
                'duration_secs' => $validated['duration_secs'],
                'is_finalised'  => true,
            ]
        );
 
        Log::info('[DV] finalise saved', ['recording_id' => $recording->id]);
 
        return response()->json(['ok' => true, 'id' => $recording->id]);
    }

    function cancelPin(Request $request, int $alertId)
    {
        $secret = $request->header('X-PTT-Secret');
        if ($secret !== env('ASSIGN_SECRET')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'cancel_pin_used' => 'nullable|string',
        ]);


        try {

                $recording = DvRecording::where('alert_id', $alertId)->first();
                if (!$recording) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Alert ID not found in DV Recordings'
                    ], 404);
                }

                $recording->cancel_pin_used = $request->cancel_pin_used ?? $recording->cancel_pin_used; // Only update if provided
                $recording->save();

                return response()->json([
                    'status'  => 'success',
                    'message' => 'GPS Synced to DV Recording'
                ]);
            

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
        
    }
}

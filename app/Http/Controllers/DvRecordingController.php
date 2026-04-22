<?php

namespace App\Http\Controllers;

use App\Models\DvRecording;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DvRecordingController extends Controller
{
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
            'Content-Disposition' => "inline; filename=\"dv_alert_{$alertId}.wav\"",
        ]);
    }
 
    // ── Called by Node.js server (internal) ───────────────────
    // Node.js calls this via HTTP after finalising the WAV file,
    // so Laravel keeps the DB up to date.
    // POST /api/internal/dv-recordings/{alertId}/finalise
    // Protected by a shared internal secret header, not user auth.
    public function finalise(Request $request, int $alertId): JsonResponse
    {
        // Verify internal call — use a strong secret in your .env
        $secret = $request->header('X-PTT-Secret');
        if ($secret !== env('ASSIGN_SECRET')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
 
        $validated = $request->validate([
            'file_path'     => 'required|string|max:500',
            'chunk_count'   => 'required|integer|min:0',
            'duration_secs' => 'required|numeric|min:0',
            'started_at'    => 'nullable|date',
        ]);
 
        $recording = DvRecording::updateOrCreate(
            ['alert_id' => $alertId],
            [
                'channel_id'    => $request->input('channel_id', 0),
                'user_id'       => $request->input('user_id', 0),
                'started_at'    => $validated['started_at'] ?? now(),
                'ended_at'      => now(),
                'file_path'     => $validated['file_path'],
                'chunk_count'   => $validated['chunk_count'],
                'duration_secs' => $validated['duration_secs'],
                'is_finalised'  => true,
            ]
        );
 
        return response()->json(['ok' => true, 'id' => $recording->id]);
    }
}

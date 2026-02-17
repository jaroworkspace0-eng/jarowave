<?php

namespace App\Http\Controllers;

use App\Models\AudioBroadcast;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class BroadcastAudioController extends Controller
{

 
    // POST /api/broadcast-audio
    public function store(Request $request)
    {


        // Validate the audio file
        $request->validate([
            'username' => 'required|string',
            'channel' => 'required|string',
            'audioBase64' => 'required|string',
            'extension' => 'required|string',
            'duration' => 'required|nullable',
        ]);

        $audioData = base64_decode($request->input('audioBase64'));
        $fileName = 'voice_' . time() . '.' . $request->input('extension');
        Storage::disk('public')->put('recordings/' . $fileName, $audioData);
        $path = 'public/recordings/' . $fileName;


        $url = Storage::url($path);

         $audio = AudioBroadcast::create([
        'user_id' => Auth::id(),
        'file_path' => $path,
        'file_url' => $url,
        'duration' => $request->duration,
        'channel_id' => 1,
    ]);

        // TODO: later we can broadcast an event here for real-time playback

    return response()->json([
        'message' => 'Audio uploaded successfully',
        'broadcast' => [
            'id' => $audio->id,
            'user' => $audio->user,
            'url' => $audio->file_url,
            'duration' => $audio->duration,
            'channel_id' => 1,
            'created_at' => $audio->created_at,
        ],
    ]);
    }
}

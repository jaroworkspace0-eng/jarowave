<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\VideoGrant;

class LiveKitController extends Controller
{
    public function generateToken(Request $request)
    {
        $request->validate([
            'room' => 'required|string',
            'participant' => 'required|string',
            'user_id' => 'required'
        ]);

        $apiKey = config('services.livekit.api_key') ?? env('LIVEKIT_API_KEY');
        $apiSecret = config('services.livekit.api_secret') ?? env('LIVEKIT_API_SECRET');

        if (!$apiKey || !$apiSecret) {
            return response()->json(['error' => 'LiveKit not configured'], 500);
        }

        try {
            // 1. Setup Token Options (Identity is handled here)
            $tokenOptions = (new AccessTokenOptions())
                ->setIdentity($request->participant);

            // 2. Setup Video Grant using proper setter methods
            $grant = (new VideoGrant())
                ->setRoomJoin(true)
                ->setRoomName($request->room)
                ->setCanPublish(true)
                ->setCanSubscribe(true);

            // 3. Create Token, Initialize with Options, and Attach Grant
            $token = (new AccessToken($apiKey, $apiSecret))
                ->init($tokenOptions)
                ->setGrant($grant);

            return response()->json([
                'token' => $token->toJwt(),
                'url' => env('LIVEKIT_URL')
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate token',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Agence104\LiveKit\AccessToken;
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

        $apiKey = env('LIVEKIT_API_KEY');
        $apiSecret = env('LIVEKIT_API_SECRET');

        if (!$apiKey || !$apiSecret) {
            return response()->json(['error' => 'LiveKit not configured'], 500);
        }

        try {
            $token = new AccessToken($apiKey, $apiSecret);
            $token->setIdentity($request->participant);
            
            $grant = new VideoGrant();
            $grant->roomJoin = true;
            $grant->room = $request->room;
            $grant->canPublish = true;
            $grant->canSubscribe = true;
            
            $token->addGrant($grant);

            return response()->json([
                'token' => $token->toJwt(),
                'url' => env('LIVEKIT_URL')
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
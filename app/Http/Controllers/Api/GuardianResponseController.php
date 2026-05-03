<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GuardianResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuardianResponseController extends Controller
{
    // GET /api/alerts/{alertId}/guardians
    public function index(string $alertId): JsonResponse
    {
        $responses = GuardianResponse::with('guardianHousehold')
            ->where('alert_id', $alertId)
            ->orderBy('notified_at')
            ->get();

        return response()->json($responses);
    }

    // POST /api/alerts/{alertId}/guardian-response
    public function store(Request $request, string $alertId): JsonResponse
    {
        $request->validate([
            'response_type' => 'required|in:acknowledged,on_way,called_police,no_response',
            'alert_type'    => 'sometimes|in:dv,sos',
        ]);

        $householdId = $request->user()->household_id;

        $response = GuardianResponse::updateOrCreate(
            [
                'alert_id'             => $alertId,
                'guardian_household_id' => $householdId,
            ],
            [
                'alert_type'    => $request->input('alert_type', 'dv'),
                'response_type' => $request->input('response_type'),
                'responded_at'  => now(),
            ]
        );

        return response()->json($response->load('guardianHousehold'));
    }
}
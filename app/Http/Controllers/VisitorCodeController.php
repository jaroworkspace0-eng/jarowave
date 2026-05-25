<?php

namespace App\Http\Controllers;

use App\Models\VisitorCode;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class VisitorCodeController extends Controller
{
    // ── POST /api/household/visitor-codes ─────────────────────────────────
    public function generate(Request $request)
    {
        $request->validate([
            'visit_type'           => 'required|in:normal,ehailing',
            'visitor_name'         => 'required|string|max:100',
            'visitor_phone'        => 'nullable|string|max:20',
            'visitor_id_number'    => 'nullable|string|max:20',
            'vehicle_registration' => 'nullable|string|max:20',
            'notes'                => 'nullable|string|max:255',
            'expected_at'          => 'nullable|date',
        ]);

        $user         = $request->user();
        $subscription = Subscription::where('user_id', $user->id)
            ->whereIn('status', ['active', 'trialing'])
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json(['message' => 'No active subscription found.'], 403);
        }

        // E-hailing expires in 30 min, normal in 24 hours
        $expiresAt = $request->visit_type === 'ehailing'
            ? now()->addMinutes(30)
            : now()->addHours(24);

        $visitorCode = VisitorCode::create([
            'user_id'              => $user->id,
            'client_id'            => $subscription->client_id,
            'visit_type'           => $request->visit_type,
            'visitor_name'         => $request->visitor_name,
            'visitor_phone'        => $request->visitor_phone,
            'visitor_id_number'    => $request->visitor_id_number,
            'vehicle_registration' => $request->vehicle_registration,
            'notes'                => $request->notes,
            'expected_at'          => $request->expected_at,
            'code'                 => VisitorCode::generateUniqueCode(),
            'qr_token'             => VisitorCode::generateQrToken(),
            'status'               => 'pending',
            'expires_at'           => $expiresAt,
        ]);

        return response()->json([
            'message'      => 'Visitor code generated.',
            'visitor_code' => $this->formatCode($visitorCode),
        ], 201);
    }

    // ── GET /api/household/visitor-codes ──────────────────────────────────
    public function index(Request $request)
    {
        $codes = VisitorCode::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($code) => $this->formatCode($code));

        return response()->json(['visitor_codes' => $codes]);
    }

    // ── DELETE /api/household/visitor-codes/{id} ──────────────────────────
    public function revoke(Request $request, $id)
    {
        $code = VisitorCode::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['pending'])
            ->first();

        if (!$code) {
            return response()->json(['message' => 'Code not found or cannot be revoked.'], 404);
        }

        $code->update(['status' => 'revoked']);

        return response()->json(['message' => 'Visitor code revoked.']);
    }

    // ── POST /api/guard/visitor-codes/verify ──────────────────────────────
    public function verify(Request $request)
    {
        $request->validate([
            'code'      => 'required_without:qr_token|string',
            'qr_token'  => 'required_without:code|string',
            'action'    => 'required|in:arrive,depart',
        ]);

        $guard = $request->user();

        // Find by code or QR token
        $visitorCode = $request->code
            ? VisitorCode::where('code', $request->code)->first()
            : VisitorCode::where('qr_token', $request->qr_token)->first();

        if (!$visitorCode) {
            return response()->json(['message' => 'Invalid code.'], 404);
        }

        // Check expiry
        if ($visitorCode->isExpired()) {
            $visitorCode->update(['status' => 'expired']);
            return response()->json(['message' => 'This code has expired.'], 400);
        }

        // Handle arrive
        if ($request->action === 'arrive') {
            if (!$visitorCode->isPending()) {
                return response()->json(['message' => 'Code already used or invalid.'], 400);
            }

            $visitorCode->update([
                'status'              => 'arrived',
                'arrived_at'          => now(),
                'arrived_verified_by' => $guard->id,
                'day_expires_at'      => now()->endOfDay(),
            ]);

            // Notify tenant via Socket.IO
            $this->notifyTenant($visitorCode, 'visitor_arrived');

            return response()->json([
                'message'      => 'Visitor marked as arrived.',
                'visitor_code' => $this->formatCode($visitorCode),
            ]);
        }

        // Handle depart
        if ($request->action === 'depart') {
            if (!$visitorCode->isArrived()) {
                return response()->json(['message' => 'Visitor has not arrived yet.'], 400);
            }

            $visitorCode->update([
                'status'               => 'departed',
                'departed_at'          => now(),
                'departed_verified_by' => $guard->id,
            ]);

            // Notify tenant via Socket.IO
            $this->notifyTenant($visitorCode, 'visitor_departed');

            return response()->json([
                'message'      => 'Visitor marked as departed.',
                'visitor_code' => $this->formatCode($visitorCode),
            ]);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function formatCode(VisitorCode $code): array
    {
        return [
            'id'                   => $code->id,
            'visit_type'           => $code->visit_type,
            'visitor_name'         => $code->visitor_name,
            'visitor_phone'        => $code->visitor_phone,
            'visitor_id_number'    => $code->visitor_id_number,
            'vehicle_registration' => $code->vehicle_registration,
            'notes'                => $code->notes,
            'code'                 => $code->code,
            'qr_token'             => $code->qr_token,
            'status'               => $code->status,
            'expected_at'          => $code->expected_at?->toIso8601String(),
            'expires_at'           => $code->expires_at?->toIso8601String(),
            'day_expires_at'       => $code->day_expires_at?->toIso8601String(),
            'arrived_at'           => $code->arrived_at?->toIso8601String(),
            'departed_at'          => $code->departed_at?->toIso8601String(),
            'created_at'           => $code->created_at?->toIso8601String(),
            'is_expired'           => $code->isExpired(),
        ];
    }

    private function notifyTenant(VisitorCode $code, string $event): void
    {
        try {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
            ])->post(env('PTT_SERVER_URL') . '/visitor-' . ($event === 'visitor_arrived' ? 'arrived' : 'departed'), [
                'userId'       => $code->user_id,
                'visitorName'  => $code->visitor_name,
                'visitType'    => $code->visit_type,
                'arrivedAt'    => $code->arrived_at?->toIso8601String(),
                'departedAt'   => $code->departed_at?->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::warning("Failed to notify tenant of {$event}: " . $e->getMessage());
        }
    }
}
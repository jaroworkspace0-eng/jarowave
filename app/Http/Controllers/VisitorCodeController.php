<?php

namespace App\Http\Controllers;

use App\Models\Client;
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
            ->where('status', 'pending')
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
            'code'                => 'required_without:qr_token|string',
            'qr_token'            => 'required_without:code|string',
            'action'              => 'required|in:arrive,depart',
            'licence_raw'         => 'nullable|string',
            'licence_scanned_at'  => 'nullable|date',
        ]);

        $guard = $request->user();

        // Find by code or QR token
        $visitorCode = $request->code
            ? VisitorCode::where('code', $request->code)->first()
            : VisitorCode::where('qr_token', $request->qr_token)->first();

        if (!$visitorCode) {
            return response()->json(['message' => 'Invalid code 1.'], 404);
        }

        // ── Estate scope check ────────────────────────────────────────────────────
        $guardClient = Client::where('user_id', $guard->id)->first();

        if (!$guardClient || (string) $visitorCode->client_id !== (string) $guardClient->id) {
            return response()->json(['message' => 'Invalid code 2.'], 404);
        }
        // ─────────────────────────────────────────────────────────────────────────

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

            $licenceParsed = [];

            if ($request->filled('licence_raw')) {
                $licenceParsed = $this->parseSALicence($request->licence_raw);
            }

            $visitorCode->update([
                'status'              => 'arrived',
                'arrived_at'          => now(),
                'arrived_verified_by' => $guard->id,
                'day_expires_at'      => now()->endOfDay(),

                // Licence fields — all nullable, won't break if not scanned
                'licence_raw'         => $request->licence_raw ?? null,
                'licence_scanned_at'  => $request->licence_scanned_at ?? null,
                'licence_id_number'   => $licenceParsed['id_number']    ?? null,
                'licence_name'        => $licenceParsed['first_names']  ?? null,
                'licence_surname'     => $licenceParsed['surname']      ?? null,
                'licence_expiry'      => $licenceParsed['expiry_date']  ?? null,
                'licence_codes'       => $licenceParsed['licence_codes'] ?? null,
            ]);

            $this->notifyTenant($visitorCode, 'visitor_arrived');

            return response()->json([
                'message'         => 'Visitor marked as arrived.',
                'visitor_code'    => $this->formatCode($visitorCode),
                'licence_scanned' => $request->filled('licence_raw'),
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

            $this->notifyTenant($visitorCode, 'visitor_departed');

            return response()->json([
                'message'      => 'Visitor marked as departed.',
                'visitor_code' => $this->formatCode($visitorCode),
            ]);
        }
    }

    // ── SA Driver's Licence Parser ────────────────────────────────────────────────

    private function parseSALicence(string $raw): array
    {
        try {
            // Decode from base64 (app should always send base64)
            $data = base64_decode($raw, strict: true);
            if ($data === false) {
                $data = $raw;
            }

            $totalLen = strlen($data);

            Log::info('SA licence raw', [
                'total_length' => $totalLen,
                'hex_preview'  => bin2hex(substr($data, 0, 20)),
            ]);

            if ($totalLen < 138) {
                Log::warning('SA licence data too short', ['length' => $totalLen]);
                return [];
            }

            // SA eNaTIS PDF-417 binary layout:
            // Bytes 0–9   : header / cert number
            // Bytes 10–137: RSA-1024 signature (128 bytes)
            // Bytes 138+  : zlib-compressed field data
            $compressed = substr($data, 138);

            $decompressed = @gzuncompress($compressed);

            if ($decompressed === false) {
                $decompressed = @gzinflate($compressed);
            }

            if ($decompressed === false) {
                Log::warning('SA licence decompression failed', [
                    'compressed_length' => strlen($compressed),
                    'hex_preview'       => bin2hex(substr($compressed, 0, 20)),
                ]);
                return [];
            }

            Log::info('SA licence decompressed', [
                'length'      => strlen($decompressed),
                'hex_preview' => bin2hex(substr($decompressed, 0, 40)),
                'ascii'       => preg_replace('/[^\x20-\x7E]/', '.', substr($decompressed, 0, 80)),
            ]);

            return $this->parseLicenceFields($decompressed);

        } catch (\Throwable $e) {
            Log::warning('SA licence parse failed', [
                'error'      => $e->getMessage(),
                'raw_length' => strlen($raw),
            ]);
            return [];
        }
    }

    private function parseLicenceFields(string $data): array
    {
        // Fixed-offset ASCII layout after decompression (SA eNaTIS spec):
        // 0   - 13 : ID number
        // 13  - 25 : surname (space-padded)
        // 38  - 25 : first names (space-padded)
        // 63  - 8  : birth date (CCYYMMDD)
        // 71  - 1  : gender (M/F)
        // 72  - 4  : licence issue number
        // 76  - 2  : vehicle codes (e.g. "B ")
        // 78  - 4  : prdp codes
        // 82  - 8  : issue date (CCYYMMDD)
        // 90  - 8  : expiry date (CCYYMMDD)
        // 98  - 4  : ID country code
        // 102 - 4  : licence country code
        // 106 - 2  : driver restrictions

        return [
            'id_number'     => trim($this->extractField($data, 0,   13)),
            'surname'       => trim($this->extractField($data, 13,  25)),
            'first_names'   => trim($this->extractField($data, 38,  25)),
            'birth_date'    => $this->parseLicenceDate($this->extractField($data, 63, 8)),
            'gender'        => trim($this->extractField($data, 71,  1)),
            'licence_codes' => trim($this->extractField($data, 76,  2)),
            'issue_date'    => $this->parseLicenceDate($this->extractField($data, 82, 8)),
            'expiry_date'   => $this->parseLicenceDate($this->extractField($data, 90, 8)),
        ];
    }

    private function extractField(string $data, int $offset, int $length): string
    {
        if (strlen($data) < $offset + $length) {
            return '';
        }
        return substr($data, $offset, $length);
    }

    private function parseLicenceDate(string $raw): ?string
    {
        $clean = preg_replace('/[^0-9]/', '', $raw);
        if (strlen($clean) !== 8) return null;

        try {
            return \Carbon\Carbon::createFromFormat('Ymd', $clean)?->toDateString();
        } catch (\Throwable) {
            return null;
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
        $endpoint = $event === 'visitor_arrived' ? '/visitor-arrived' : '/visitor-departed';

        try {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
            ])->post(env('PTT_SERVER_URL') . $endpoint, [
                'userId'      => $code->user_id,
                'visitorName' => $code->visitor_name,
                'visitType'   => $code->visit_type,
                'arrivedAt'   => $code->arrived_at?->toIso8601String(),
                'departedAt'  => $code->departed_at?->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::warning("Failed to notify tenant of {$event}: " . $e->getMessage());
        }
    }
}
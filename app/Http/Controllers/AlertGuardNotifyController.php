<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\EmergencyAlert;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AlertGuardNotifyController extends Controller
{
    private string $nodeUrl;

    public function __construct()
    {
        // Confirm this matches however SendPaymentReminders sets it —
        // config('services.node.url') if that's where it comes from,
        // env() directly if not.
        $this->nodeUrl = rtrim(env('PTT_SERVER_URL', 'https://radio.server.jaroworkspace.com'), '/');
    }

    // POST /api/alerts/{alert}/notify-guards
    // Body matches the payload AlertCard.vue emits:
    // { target: 'all'|'responder'|'selected', guardIds: number[], message: string }
    public function __invoke(Request $request, EmergencyAlert $alert)
    {
        $data = $request->validate([
            'target' => 'required|in:all,responder,selected',
            'guardIds' => 'required|array|min:1',
            'guardIds.*' => 'integer',
            'message' => 'required|string|max:240',
        ]);

        $guards = User::where('role', 'guard')->whereIn('id', $data['guardIds'])->get();

        if ($guards->isEmpty()) {
            return response()->json(['message' => 'No matching guards found.'], 422);
        }

        $title = $this->titleFor($alert);

        foreach ($guards as $guard) {
            $this->notifyNode('POST', '/send-notification', [
                'userId' => $guard->id,
                'title' => $title,
                'message' => $data['message'],
            ]);
        }

        $alert->events()->create([
            'actor_type' => 'admin',
            'event_type' => 'admin_call_logged', // swap for a dedicated
            // 'guards_broadcast' event_type if you add one to eventLabel()
            'payload' => [
                'outcome' => "Broadcast to {$guards->count()} guard(s): {$data['message']}",
            ],
        ]);

        return response()->json(['sent' => $guards->count()]);
    }

    private function titleFor(EmergencyAlert $alert): string
    {
        return match ($alert->type) {
            'panic', 'sos' => "Panic alert — {$alert->household_name}",
            'domestic_violence' => 'DV alert update',
            default => "Alert update — {$alert->household_name}",
        };
    }

    // Copied to match SendPaymentReminders::notifyNode() exactly.
    private function notifyNode(string $method, string $path, array $payload): void
    {
        try {
            Http::timeout(5)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
                    'Content-Type' => 'application/json',
                ])
                ->{strtolower($method)}($this->nodeUrl . $path, $payload);
        } catch (\Throwable $e) {
            Log::warning('AlertGuardNotifyController: Node notify failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
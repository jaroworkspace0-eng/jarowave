<?php
namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use App\Models\HouseholdSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    protected string $socketUrl;
    protected string $secret;

    public function __construct()
    {
        $this->socketUrl = env('PTT_SERVER_URL', 'https://radio.server.jaroworkspace.com');
        $this->secret    = env('ASSIGN_SECRET', '');
    }

    public function send(
        User $recipient,
        string $type,
        string $title,
        string $body,
        array $data = [],
    ): void {
        // ── Check user settings before sending ──
        if (!$this->isAllowed($recipient->id, $type)) {
            Log::info("NotificationService: suppressed '{$type}' for user {$recipient->id} (settings off)");
            return;
        }

        // 1. Save to DB always
        UserNotification::create([
            'user_id' => $recipient->id,
            'type'    => $type,
            'title'   => $title,
            'body'    => $body,
            'data'    => $data,
            'is_read' => false,
        ]);

        // 2. Notify socket server (handles online socket + FCM push)
        try {
            Http::withHeaders([
                'Authorization' => "Bearer {$this->secret}",
                'Content-Type'  => 'application/json',
            ])
            ->timeout(6)
            ->post("{$this->socketUrl}/notify-pairing", [
                'userId'  => $recipient->id,
                'type'    => $type,
                'title'   => $title,
                'body'    => $body,
                'data'    => $data,
            ]);
        } catch (\Throwable $e) {
            Log::warning("NotificationService: socket notify failed — {$e->getMessage()}");
        }
    }

    private function isAllowed(int $userId, string $type): bool
    {
        $settings = HouseholdSetting::where('user_id', $userId)->first();

        // no settings row = all defaults = allow everything
        if (!$settings) return true;

        return match(true) {
            // SOS alerts
            in_array($type, ['sos', 'sos_alert', 'guardian_sos'])
                => $settings->sos_alerts,

            // All-clear notifications
            in_array($type, ['all_clear', 'safe', 'guardian_safe'])
                => $settings->all_clear,

            // Everything else (pairing requests, system msgs etc) always goes through
            default => true,
        };
    }
}
<?php
// app/Services/NotificationService.php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
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
}
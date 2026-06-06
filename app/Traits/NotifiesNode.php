<?php

namespace App\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait NotifiesNode
{
    private function notifyNode(string $method, string $path, array $payload): void
    {
        try {
            Http::timeout(5)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
                    'Content-Type'  => 'application/json',
                ])
                ->{strtolower($method)}(rtrim(env('PTT_SERVER_URL', ''), '/') . $path, $payload);
        } catch (\Throwable $e) {
            Log::warning('Node notify failed', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }
}